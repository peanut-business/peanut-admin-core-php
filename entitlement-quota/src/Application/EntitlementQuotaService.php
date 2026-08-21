<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Application;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\EntitlementQuota\Contract\EntitlementGrantSnapshot;
use PeanutAdmin\EntitlementQuota\Contract\EntitlementMeter;
use PeanutAdmin\EntitlementQuota\Contract\EntitlementMeterRegistry;
use PeanutAdmin\EntitlementQuota\Contract\EntitlementPolicyProvider;
use PeanutAdmin\EntitlementQuota\Model\EntitlementPolicyRevision;
use PeanutAdmin\EntitlementQuota\Model\EntitlementReservation;
use PeanutAdmin\EntitlementQuota\Model\EntitlementUsageWindow;
use PeanutAdmin\EntitlementQuota\Package;
use PeanutAdmin\EntitlementQuota\Persistence\EntitlementQuotaRepository;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Auth\Clock;
use PeanutAdmin\Kernel\Auth\SystemClock;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Idempotency\IdempotencyKey;
use PeanutAdmin\Kernel\Idempotency\PdoIdempotencyRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoAuditRepository;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoTransactionManager;
use RuntimeException;
use Throwable;
use UnexpectedValueException;

final readonly class EntitlementQuotaService
{
    private Clock $clock;
    private PdoTransactionManager $transactions;
    private PdoIdempotencyRepository $idempotency;
    private PdoAuditRepository $audit;

    public function __construct(
        private EntitlementQuotaRepository $repository,
        private EntitlementMeterRegistry $meters,
        private EntitlementPolicyProvider $policies,
        ?Clock $clock = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $pdo = $repository->connection();
        $this->transactions = new PdoTransactionManager($pdo);
        $this->idempotency = new PdoIdempotencyRepository($pdo);
        $this->audit = new PdoAuditRepository($pdo);
    }

    public function check(
        AuthorizedOperationContext $context,
        string $meterKey,
        string $targetType,
        string $targetKey,
        int $amount,
    ): EntitlementQuotaDecision {
        $this->assertRequest($context, $meterKey, $targetType, $targetKey);
        $this->assertPositive($amount);
        $meter = $this->meter($meterKey, $targetType);
        $now = $this->now();
        $snapshot = $this->snapshot($context, $meter, $targetKey, $now);

        try {
            $usage = $this->readUsage($context, $meter, $targetKey, $snapshot, $now);
            $allowed = $amount <= $usage->remainingAmount;

            return new EntitlementQuotaDecision(
                $allowed,
                $usage->meterKey,
                $usage->targetType,
                $usage->targetKey,
                $usage->unitKey,
                $amount,
                $usage->committedAmount,
                $usage->reservedAmount,
                $usage->limitAmount,
                $usage->remainingAmount,
                $usage->windowStart,
                $usage->windowEnd,
                $usage->policySnapshotSha256,
            );
        } catch (EntitlementQuotaException $exception) {
            throw $exception;
        } catch (UnexpectedValueException) {
            throw EntitlementQuotaException::integrityFailure();
        } catch (Throwable) {
            throw EntitlementQuotaException::internal();
        }
    }

    public function reserve(
        AuthorizedOperationContext $context,
        string $meterKey,
        string $targetType,
        string $targetKey,
        int $amount,
        string $idempotencyKey,
    ): EntitlementQuotaReceipt {
        $this->assertRequest($context, $meterKey, $targetType, $targetKey);
        $this->assertPositive($amount);
        IdempotencyKey::fromString($idempotencyKey);
        $meter = $this->meter($meterKey, $targetType);
        $now = $this->now();
        $snapshot = $this->snapshot($context, $meter, $targetKey, $now);
        [$windowStart, $windowEnd] = $this->window($snapshot, $now);
        $canonicalSnapshot = $this->canonicalSnapshot($snapshot);
        $policyDigest = hash('sha256', $canonicalSnapshot);

        return $this->command(
            $context,
            Package::RESERVE_OPERATION,
            [
                'amount' => $amount,
                'authorization_basis_digest' => $context->authorizationBasisDigest,
                'authorized_operation' => $context->operation,
                'authorized_resource' => $context->resourceKey,
                'meter_key' => $meterKey,
                'target_key' => $targetKey,
                'target_type' => $targetType,
            ],
            $idempotencyKey,
            function (string $timestamp) use (
                $context,
                $meter,
                $targetKey,
                $amount,
                $snapshot,
                $windowStart,
                $windowEnd,
                $canonicalSnapshot,
                $policyDigest,
                $now,
            ): EntitlementQuotaReceipt {
                $tenantId = $context->tenantContext->tenantId;
                $grant = $this->repository->grant($tenantId, $snapshot->grantKey, true);
                if ($grant !== null && !$grant->isActive()) {
                    throw EntitlementQuotaException::denied();
                }
                $policy = $this->repository->lockOrCreatePolicyRevision(
                    $tenantId,
                    $snapshot->grantKey,
                    $snapshot->policyRevisionKey,
                    $snapshot->meterKey,
                    $snapshot->unitKey,
                    $snapshot->limitAmount,
                    $snapshot->periodKind,
                    $this->databaseTime($snapshot->effectiveFrom),
                    $this->databaseTime($snapshot->effectiveUntil),
                    $snapshot->reservationTtlSeconds,
                    $canonicalSnapshot,
                    $policyDigest,
                    $context->tenantContext->memberId,
                    $timestamp,
                );
                $this->assertPolicy($policy, $snapshot, $canonicalSnapshot, $policyDigest);
                $window = $this->repository->lockOrCreateUsageWindow(
                    $tenantId,
                    $policy->id,
                    $meter->meterKey,
                    $meter->targetType,
                    $targetKey,
                    $this->databaseTime($windowStart),
                    $this->databaseTime($windowEnd),
                    $timestamp,
                );
                $expiresAt = $now->modify('+' . $snapshot->reservationTtlSeconds . ' seconds');
                if ($expiresAt > $windowEnd) {
                    $expiresAt = $windowEnd;
                }
                $reservation = $this->repository->createReservation(
                    $tenantId,
                    $window->id,
                    'reservation_' . bin2hex(random_bytes(16)),
                    $amount,
                    $snapshot->limitAmount,
                    $context->tenantContext->memberId,
                    $timestamp,
                    $this->databaseTime($expiresAt),
                );
                $reserved = $this->repository->livePendingAmount($tenantId, $window->id, $timestamp);
                $receipt = $this->receipt(
                    Package::RESERVE_OPERATION,
                    $meter,
                    $targetKey,
                    $reservation,
                    $window,
                    $policy,
                    $reserved,
                );
                $this->appendAudit($context, 'tenant.entitlement_quota.reserved', $receipt);

                return $receipt;
            },
            $now,
        );
    }

    public function commit(
        AuthorizedOperationContext $context,
        string $meterKey,
        string $targetType,
        string $targetKey,
        string $reservationKey,
        string $idempotencyKey,
    ): EntitlementQuotaReceipt {
        return $this->settle(
            $context,
            $meterKey,
            $targetType,
            $targetKey,
            $reservationKey,
            $idempotencyKey,
            Package::COMMIT_OPERATION,
            'committed',
            'tenant.entitlement_quota.committed',
        );
    }

    public function release(
        AuthorizedOperationContext $context,
        string $meterKey,
        string $targetType,
        string $targetKey,
        string $reservationKey,
        string $idempotencyKey,
    ): EntitlementQuotaReceipt {
        return $this->settle(
            $context,
            $meterKey,
            $targetType,
            $targetKey,
            $reservationKey,
            $idempotencyKey,
            Package::RELEASE_OPERATION,
            'released',
            'tenant.entitlement_quota.released',
        );
    }

    public function usage(
        AuthorizedOperationContext $context,
        string $meterKey,
        string $targetType,
        string $targetKey,
    ): EntitlementQuotaUsage {
        $this->assertRequest($context, $meterKey, $targetType, $targetKey);
        $meter = $this->meter($meterKey, $targetType);
        $now = $this->now();
        $snapshot = $this->snapshot($context, $meter, $targetKey, $now);

        try {
            return $this->readUsage($context, $meter, $targetKey, $snapshot, $now);
        } catch (EntitlementQuotaException $exception) {
            throw $exception;
        } catch (UnexpectedValueException) {
            throw EntitlementQuotaException::integrityFailure();
        } catch (Throwable) {
            throw EntitlementQuotaException::internal();
        }
    }

    private function settle(
        AuthorizedOperationContext $context,
        string $meterKey,
        string $targetType,
        string $targetKey,
        string $reservationKey,
        string $idempotencyKey,
        string $operation,
        string $state,
        string $eventType,
    ): EntitlementQuotaReceipt {
        $this->assertRequest($context, $meterKey, $targetType, $targetKey);
        $this->assertReservationKey($reservationKey);
        $meter = $this->meter($meterKey, $targetType);

        return $this->command(
            $context,
            $operation,
            [
                'authorization_basis_digest' => $context->authorizationBasisDigest,
                'authorized_operation' => $context->operation,
                'authorized_resource' => $context->resourceKey,
                'meter_key' => $meterKey,
                'reservation_key' => $reservationKey,
                'target_key' => $targetKey,
                'target_type' => $targetType,
            ],
            $idempotencyKey,
            function (string $timestamp) use (
                $context,
                $meter,
                $targetKey,
                $reservationKey,
                $operation,
                $state,
                $eventType,
            ): EntitlementQuotaReceipt|EntitlementQuotaException {
                $tenantId = $context->tenantContext->tenantId;
                $reservation = $this->repository->reservation($tenantId, $reservationKey);
                if ($reservation === null || !$this->reservationMatches($reservation, $meter, $targetKey)) {
                    throw EntitlementQuotaException::notFound();
                }
                $window = $this->repository->usageWindowById($tenantId, $reservation->usageWindowId, true)
                    ?? throw EntitlementQuotaException::notFound();
                $locked = $this->repository->reservation($tenantId, $reservationKey, true);
                if ($locked === null || !$this->reservationMatches($locked, $meter, $targetKey)) {
                    throw EntitlementQuotaException::notFound();
                }
                if (!$locked->isPending()) {
                    throw EntitlementQuotaException::conflict();
                }
                $settled = $this->repository->settleReservation(
                    $tenantId,
                    $reservationKey,
                    $state,
                    $context->tenantContext->memberId,
                    $timestamp,
                );
                if ($settled->state === 'expired') {
                    return EntitlementQuotaException::conflict();
                }
                if ($settled->state !== $state) {
                    throw EntitlementQuotaException::integrityFailure();
                }
                $updatedWindow = $this->repository->usageWindowById($tenantId, $window->id, true)
                    ?? throw EntitlementQuotaException::integrityFailure();
                $policy = $this->repository->policyRevisionById(
                    $tenantId,
                    $updatedWindow->policyRevisionId,
                ) ?? throw EntitlementQuotaException::integrityFailure();
                $policy->assertSnapshotIntegrity();
                $reserved = $this->repository->livePendingAmount($tenantId, $updatedWindow->id, $timestamp);
                $receipt = $this->receipt(
                    $operation,
                    $meter,
                    $targetKey,
                    $settled,
                    $updatedWindow,
                    $policy,
                    $reserved,
                );
                $this->appendAudit($context, $eventType, $receipt);

                return $receipt;
            },
        );
    }

    private function readUsage(
        AuthorizedOperationContext $context,
        EntitlementMeter $meter,
        string $targetKey,
        EntitlementGrantSnapshot $snapshot,
        DateTimeImmutable $now,
    ): EntitlementQuotaUsage {
        $tenantId = $context->tenantContext->tenantId;
        $grant = $this->repository->grant($tenantId, $snapshot->grantKey);
        if ($grant !== null && !$grant->isActive()) {
            throw EntitlementQuotaException::denied();
        }
        [$windowStart, $windowEnd] = $this->window($snapshot, $now);
        $canonical = $this->canonicalSnapshot($snapshot);
        $digest = hash('sha256', $canonical);
        $policy = $this->repository->policyRevision($tenantId, $snapshot->policyRevisionKey);
        $committed = 0;
        $reserved = 0;
        if ($policy !== null) {
            $this->assertPolicy($policy, $snapshot, $canonical, $digest);
            $window = $this->repository->usageWindow(
                $tenantId,
                $policy->id,
                $meter->meterKey,
                $meter->targetType,
                $targetKey,
                $this->databaseTime($windowStart),
            );
            if ($window !== null) {
                $this->assertWindow($window, $policy, $meter, $targetKey, $windowStart, $windowEnd);
                $committed = $window->committedAmount;
                $reserved = $this->repository->livePendingAmount(
                    $tenantId,
                    $window->id,
                    $this->databaseTime($now),
                );
            }
        }
        $remaining = $this->remaining($snapshot->limitAmount, $committed, $reserved);

        return new EntitlementQuotaUsage(
            $meter->meterKey,
            $meter->targetType,
            $targetKey,
            $meter->unitKey,
            $committed,
            $reserved,
            $snapshot->limitAmount,
            $remaining,
            $this->isoTime($windowStart),
            $this->isoTime($windowEnd),
            $digest,
        );
    }

    /**
     * @param array<string, int|string> $semanticInputs
     * @param callable(string): (EntitlementQuotaReceipt|EntitlementQuotaException) $operation
     */
    private function command(
        AuthorizedOperationContext $context,
        string $operationKey,
        array $semanticInputs,
        string $rawIdempotencyKey,
        callable $operation,
        ?DateTimeImmutable $comparisonTime = null,
    ): EntitlementQuotaReceipt {
        try {
            $key = IdempotencyKey::fromString($rawIdempotencyKey);
            $requestHash = hash('sha256', $this->canonicalJson($semanticInputs));
            $now = $comparisonTime ?? $this->now();
            $expiresAt = $now->modify('+1 day');

            $result = $this->transactions->run(function () use (
                $context,
                $operationKey,
                $key,
                $requestHash,
                $now,
                $expiresAt,
                $operation,
                $semanticInputs,
            ): EntitlementQuotaReceipt|EntitlementQuotaException {
                $record = $this->idempotency->beginTenant(
                    $context->tenantContext->tenantId,
                    $context->tenantContext->memberId,
                    $operationKey,
                    $key,
                    $requestHash,
                    $expiresAt,
                    $now,
                );
                if ($record->replayable()) {
                    if (($record->responseStatus ?? 500) >= 400) {
                        return $this->failureFromArray((array) $record->responseBody);
                    }

                    return EntitlementQuotaReceipt::fromArray((array) $record->responseBody, $operationKey);
                }
                if (!$record->acquiredForExecution()) {
                    throw new ApiException(
                        'IDEMPOTENCY_REQUEST_PROCESSING',
                        409,
                        'Another entitlement quota request with this Idempotency-Key is processing.',
                    );
                }
                $receipt = $operation($this->databaseTime($now));
                if ($receipt instanceof EntitlementQuotaException) {
                    $this->idempotency->failTenant(
                        $record->id,
                        $receipt->httpStatus,
                        $this->failureToArray($receipt),
                        'entitlement_reservation',
                        is_string($semanticInputs['reservation_key'] ?? null)
                            ? $semanticInputs['reservation_key']
                            : null,
                    );

                    return $receipt;
                }
                $this->idempotency->completeTenant(
                    $record->id,
                    200,
                    $receipt->toArray(),
                    'entitlement_reservation',
                    $receipt->reservationKey,
                );

                return $receipt;
            });
            if ($result instanceof EntitlementQuotaException) {
                throw $result;
            }

            return $result;
        } catch (EntitlementQuotaException|ApiException $exception) {
            throw $exception;
        } catch (UnexpectedValueException $exception) {
            throw $this->mapUnexpectedFailure($exception);
        } catch (RuntimeException $exception) {
            throw $this->mapRepositoryFailure($exception);
        } catch (Throwable) {
            throw EntitlementQuotaException::internal();
        }
    }

    private function receipt(
        string $operation,
        EntitlementMeter $meter,
        string $targetKey,
        EntitlementReservation $reservation,
        EntitlementUsageWindow $window,
        EntitlementPolicyRevision $policy,
        int $reservedAmount,
    ): EntitlementQuotaReceipt {
        if (!hash_equals($policy->meterKey, $meter->meterKey)
            || !hash_equals($policy->unitKey, $meter->unitKey)
            || $policy->limitAmount < 1
            || !in_array($policy->periodKind, ['lifetime', 'utc_day', 'utc_month'], true)
            || preg_match('/^[0-9a-f]{64}$/D', $policy->canonicalSnapshotSha256) !== 1) {
            throw EntitlementQuotaException::integrityFailure();
        }
        $this->assertWindowIdentity($window, $policy, $meter, $targetKey);
        if ($reservation->tenantId !== $window->tenantId
            || $reservation->usageWindowId !== $window->id
            || !$this->reservationMatches($reservation, $meter, $targetKey)) {
            throw EntitlementQuotaException::integrityFailure();
        }
        $remaining = $this->remaining($policy->limitAmount, $window->committedAmount, $reservedAmount);

        return new EntitlementQuotaReceipt(
            $operation,
            $meter->meterKey,
            $meter->targetType,
            $targetKey,
            $meter->unitKey,
            $reservation->reservationKey,
            $reservation->amount,
            $reservation->state,
            $window->committedAmount,
            $reservedAmount,
            $policy->limitAmount,
            $remaining,
            $this->isoDatabaseTime($window->windowStart),
            $this->isoDatabaseTime($window->windowEnd),
            $this->isoDatabaseTime($reservation->reservedAt),
            $this->isoDatabaseTime($reservation->expiresAt),
            $reservation->settledAt === null ? null : $this->isoDatabaseTime($reservation->settledAt),
            $policy->canonicalSnapshotSha256,
        );
    }

    private function appendAudit(
        AuthorizedOperationContext $context,
        string $eventType,
        EntitlementQuotaReceipt $receipt,
    ): void {
        $this->audit->appendTenantMember(
            $context->tenantContext,
            $eventType,
            $context->resourceKey . '.' . $context->operation,
            $receipt->targetType,
            $receipt->targetKey,
            targetCount: 1,
            metadata: [
                'meter_key' => $receipt->meterKey,
                'target_type' => $receipt->targetType,
                'target_key' => $receipt->targetKey,
                'reservation_key' => $receipt->reservationKey,
                'amount' => $receipt->amount,
                'state' => $receipt->state,
                'policy_snapshot_sha256' => $receipt->policySnapshotSha256,
                'committed_amount' => $receipt->committedAmount,
                'reserved_amount' => $receipt->reservedAmount,
                'limit_amount' => $receipt->limitAmount,
            ],
        );
    }

    private function meter(string $meterKey, string $targetType): EntitlementMeter
    {
        try {
            $meter = $this->meters->find($meterKey, $targetType);
        } catch (Throwable) {
            throw EntitlementQuotaException::denied();
        }
        if ($meter === null
            || !hash_equals($meterKey, $meter->meterKey)
            || !hash_equals($targetType, $meter->targetType)
            || !$this->validIdentifier($meter->unitKey)) {
            throw EntitlementQuotaException::denied();
        }

        return $meter;
    }

    private function snapshot(
        AuthorizedOperationContext $context,
        EntitlementMeter $meter,
        string $targetKey,
        DateTimeImmutable $now,
    ): EntitlementGrantSnapshot {
        try {
            $snapshot = $this->policies->snapshot($context, $meter, $targetKey, $now);
        } catch (Throwable) {
            throw EntitlementQuotaException::providerUnavailable();
        }
        if ($snapshot === null) {
            throw EntitlementQuotaException::denied();
        }
        if (!$this->validIdentifier($snapshot->grantKey)
            || !$this->validIdentifier($snapshot->policyRevisionKey)
            || !$this->validIdentifier($snapshot->meterKey)
            || !$this->validIdentifier($snapshot->unitKey)
            || !hash_equals($meter->meterKey, $snapshot->meterKey)
            || !hash_equals($meter->unitKey, $snapshot->unitKey)
            || $snapshot->limitAmount < 1
            || !in_array($snapshot->periodKind, ['lifetime', 'utc_day', 'utc_month'], true)
            || $snapshot->effectiveUntil <= $snapshot->effectiveFrom
            || $snapshot->reservationTtlSeconds < 30
            || $snapshot->reservationTtlSeconds > 86400) {
            throw EntitlementQuotaException::integrityFailure();
        }
        if ($now < $snapshot->effectiveFrom || $now >= $snapshot->effectiveUntil) {
            throw EntitlementQuotaException::denied();
        }

        return $snapshot;
    }

    /** @return array{DateTimeImmutable, DateTimeImmutable} */
    private function window(EntitlementGrantSnapshot $snapshot, DateTimeImmutable $now): array
    {
        $start = match ($snapshot->periodKind) {
            'lifetime' => $snapshot->effectiveFrom,
            'utc_day' => $now->setTime(0, 0),
            'utc_month' => $now->modify('first day of this month')->setTime(0, 0),
            default => throw EntitlementQuotaException::integrityFailure(),
        };
        $end = match ($snapshot->periodKind) {
            'lifetime' => $snapshot->effectiveUntil,
            'utc_day' => $start->modify('+1 day'),
            'utc_month' => $start->modify('+1 month'),
            default => throw EntitlementQuotaException::integrityFailure(),
        };
        if ($start < $snapshot->effectiveFrom) {
            $start = $snapshot->effectiveFrom;
        }
        if ($end > $snapshot->effectiveUntil) {
            $end = $snapshot->effectiveUntil;
        }
        if ($now < $start || $now >= $end || $end <= $start) {
            throw EntitlementQuotaException::denied();
        }

        return [$start, $end];
    }

    private function assertRequest(
        AuthorizedOperationContext $context,
        string $meterKey,
        string $targetType,
        string $targetKey,
    ): void {
        if ($context->tenantContext->tenantId < 1
            || $context->tenantContext->memberId < 1
            || $context->tenantContext->accountId < 1
            || $context->tenantContext->requestId === ''
            || $context->resourceKey === ''
            || $context->operation === '') {
            throw EntitlementQuotaException::invalid();
        }
        $this->assertIdentifier($meterKey);
        $this->assertIdentifier($targetType);
        $this->assertAscii($targetKey, 128);
        $targets = array_values($context->targets);
        $target = $targets[0] ?? null;
        if (count($targets) !== 1
            || $target === null
            || $target->targetRole !== 'primary'
            || !hash_equals($targetType, $target->targetResourceKey)
            || count($target->targetIds) !== 1
            || !hash_equals($targetKey, $target->targetIds[0])) {
            throw EntitlementQuotaException::notFound();
        }
    }

    private function assertPolicy(
        EntitlementPolicyRevision $policy,
        EntitlementGrantSnapshot $snapshot,
        string $canonical,
        string $digest,
    ): void {
        $policy->assertSnapshotIntegrity();
        if (!hash_equals($policy->policyRevisionKey, $snapshot->policyRevisionKey)
            || !hash_equals($policy->meterKey, $snapshot->meterKey)
            || !hash_equals($policy->unitKey, $snapshot->unitKey)
            || $policy->limitAmount !== $snapshot->limitAmount
            || !hash_equals($policy->periodKind, $snapshot->periodKind)
            || !hash_equals($policy->effectiveFrom, $this->databaseTime($snapshot->effectiveFrom))
            || !hash_equals($policy->effectiveUntil, $this->databaseTime($snapshot->effectiveUntil))
            || $policy->reservationTtlSeconds !== $snapshot->reservationTtlSeconds
            || !hash_equals($policy->canonicalSnapshotJson, $canonical)
            || !hash_equals($policy->canonicalSnapshotSha256, $digest)) {
            throw EntitlementQuotaException::integrityFailure();
        }
    }

    private function assertWindow(
        EntitlementUsageWindow $window,
        EntitlementPolicyRevision $policy,
        EntitlementMeter $meter,
        string $targetKey,
        DateTimeImmutable $windowStart,
        DateTimeImmutable $windowEnd,
    ): void {
        $this->assertWindowIdentity($window, $policy, $meter, $targetKey);
        if (!hash_equals($window->windowStart, $this->databaseTime($windowStart))
            || !hash_equals($window->windowEnd, $this->databaseTime($windowEnd))) {
            throw EntitlementQuotaException::integrityFailure();
        }
    }

    private function assertWindowIdentity(
        EntitlementUsageWindow $window,
        EntitlementPolicyRevision $policy,
        EntitlementMeter $meter,
        string $targetKey,
    ): void {
        if ($window->tenantId !== $policy->tenantId
            || $window->policyRevisionId !== $policy->id
            || !hash_equals($window->meterKey, $meter->meterKey)
            || !hash_equals($window->targetType, $meter->targetType)
            || !hash_equals($window->targetKey, $targetKey)
            || $window->committedAmount < 0) {
            throw EntitlementQuotaException::integrityFailure();
        }
    }

    private function reservationMatches(
        EntitlementReservation $reservation,
        EntitlementMeter $meter,
        string $targetKey,
    ): bool {
        return hash_equals($reservation->meterKey, $meter->meterKey)
            && hash_equals($reservation->targetType, $meter->targetType)
            && hash_equals($reservation->targetKey, $targetKey);
    }

    private function remaining(int $limit, int $committed, int $reserved): int
    {
        if ($limit < 1 || $committed < 0 || $reserved < 0 || $committed > PHP_INT_MAX - $reserved) {
            throw EntitlementQuotaException::integrityFailure();
        }
        $used = $committed + $reserved;
        if ($used > $limit) {
            throw EntitlementQuotaException::integrityFailure();
        }

        return $limit - $used;
    }

    private function assertIdentifier(string $value): void
    {
        if (!$this->validIdentifier($value)) {
            throw EntitlementQuotaException::invalid();
        }
    }

    private function validIdentifier(string $value): bool
    {
        return strlen($value) <= 64
            && preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $value) === 1;
    }

    private function assertAscii(string $value, int $maximumLength): void
    {
        if (strlen($value) > $maximumLength || preg_match('/^[\x20-\x7E]+$/D', $value) !== 1) {
            throw EntitlementQuotaException::invalid();
        }
    }

    private function assertPositive(int $value): void
    {
        if ($value < 1) {
            throw EntitlementQuotaException::invalid();
        }
    }

    private function assertReservationKey(string $value): void
    {
        if (preg_match('/^reservation_[0-9a-f]{32}$/D', $value) !== 1) {
            throw EntitlementQuotaException::invalid();
        }
    }

    private function canonicalSnapshot(EntitlementGrantSnapshot $snapshot): string
    {
        return $this->canonicalJson($snapshot->toArray());
    }

    /** @param array<string, int|string> $value */
    private function canonicalJson(array $value): string
    {
        ksort($value, SORT_STRING);
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException) {
            throw EntitlementQuotaException::internal();
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    private function databaseTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function isoTime(DateTimeImmutable $value): string
    {
        return $value->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
    }

    private function isoDatabaseTime(string $value): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value, new DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d H:i:s.v') !== $value) {
            throw EntitlementQuotaException::integrityFailure();
        }

        return $this->isoTime($parsed);
    }

    private function mapRepositoryFailure(RuntimeException $exception): EntitlementQuotaException
    {
        $message = strtolower($exception->getMessage());
        if (str_contains($message, 'snapshot')
            || str_contains($message, 'digest')
            || str_contains($message, 'integrity')) {
            return EntitlementQuotaException::integrityFailure();
        }
        if (str_contains($message, 'capacity') || str_contains($message, 'exceed')) {
            return EntitlementQuotaException::exceeded();
        }
        if (str_contains($message, 'unavailable') || str_contains($message, 'not found')) {
            return EntitlementQuotaException::notFound();
        }
        if (str_contains($message, 'suspended')) {
            return EntitlementQuotaException::denied();
        }
        if (str_contains($message, 'changed')
            || str_contains($message, 'conflict')
            || str_contains($message, 'pending')
            || str_contains($message, 'state')
            || str_contains($message, 'identity already exists')) {
            return EntitlementQuotaException::conflict();
        }

        return EntitlementQuotaException::internal();
    }

    private function mapUnexpectedFailure(UnexpectedValueException $exception): EntitlementQuotaException
    {
        $message = strtolower($exception->getMessage());

        return !str_contains($message, 'overflow') && str_contains($message, 'changed')
            ? EntitlementQuotaException::conflict()
            : EntitlementQuotaException::integrityFailure();
    }

    /** @return array{error_code: string, http_status: int, message: string} */
    private function failureToArray(EntitlementQuotaException $exception): array
    {
        return [
            'error_code' => $exception->errorCode,
            'http_status' => $exception->httpStatus,
            'message' => $exception->getMessage(),
        ];
    }

    /** @param array<string, mixed> $value */
    private function failureFromArray(array $value): EntitlementQuotaException
    {
        if (array_keys($value) !== ['error_code', 'http_status', 'message']
            || $value['error_code'] !== 'ENTITLEMENT_QUOTA_CONFLICT'
            || $value['http_status'] !== 409
            || !is_string($value['message'])) {
            throw EntitlementQuotaException::internal();
        }

        return EntitlementQuotaException::conflict();
    }
}
