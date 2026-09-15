<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Persistence;

use InvalidArgumentException;
use PDO;
use PeanutAdmin\EntitlementQuota\Model\EntitlementGrant;
use PeanutAdmin\EntitlementQuota\Model\EntitlementPolicyRevision;
use PeanutAdmin\EntitlementQuota\Model\EntitlementReservation;
use PeanutAdmin\EntitlementQuota\Model\EntitlementUsageWindow;
use PeanutAdmin\EntitlementQuota\Persistence\Model\EntitlementGrantRecord;
use PeanutAdmin\EntitlementQuota\Persistence\Model\EntitlementPolicyRevisionRecord;
use PeanutAdmin\EntitlementQuota\Persistence\Model\EntitlementReservationRecord;
use PeanutAdmin\EntitlementQuota\Persistence\Model\EntitlementUsageLedgerRecord;
use PeanutAdmin\EntitlementQuota\Persistence\Model\EntitlementUsageWindowRecord;
use PeanutAdmin\Kernel\Tenancy\TenantScope;
use RuntimeException;
use think\db\exception\PDOException;
use think\db\PDOConnection;
use think\db\Raw;
use think\facade\Db;
use think\Model;
use UnexpectedValueException;

/** ThinkORM persistence for quota reservation, capacity and immutable policy rules. */
final readonly class ThinkPhpEntitlementQuotaRepository
{
    public function grant(int $tenantId, string $grantKey, bool $forUpdate = false): ?EntitlementGrant
    {
        $query = EntitlementGrantRecord::scope('tenant', $this->scope($tenantId))
            ->where('grant_key', $grantKey);
        if ($forUpdate) {
            $query->lock(true);
        }

        return $this->mapOne($query->find(), EntitlementGrant::fromRow(...));
    }

    public function lockOrCreatePolicyRevision(
        int $tenantId,
        string $grantKey,
        string $policyRevisionKey,
        string $meterKey,
        string $unitKey,
        int $limitAmount,
        string $periodKind,
        string $effectiveFrom,
        string $effectiveUntil,
        int $reservationTtlSeconds,
        string $canonicalSnapshotJson,
        string $canonicalSnapshotSha256,
        int $memberId,
        string $now,
    ): EntitlementPolicyRevision {
        $this->assertCallerOwnedTransaction();
        if (!hash_equals($canonicalSnapshotSha256, hash('sha256', $canonicalSnapshotJson))) {
            throw new UnexpectedValueException('Entitlement policy snapshot digest is invalid.');
        }

        $grant = $this->grant($tenantId, $grantKey, true);
        if ($grant === null) {
            try {
                (new EntitlementGrantRecord())->save([
                    'tenant_id' => $tenantId,
                    'grant_key' => $grantKey,
                    'state' => 'active',
                    'current_policy_revision_id' => null,
                    'revision' => 1,
                    'created_by_member_id' => $memberId,
                    'updated_by_member_id' => $memberId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (PDOException $exception) {
                if (!$this->isDuplicate($exception)) {
                    throw $exception;
                }
            }
            $grant = $this->grant($tenantId, $grantKey, true)
                ?? throw new RuntimeException('The entitlement grant could not be read back.');
        }
        if (!$grant->isActive()) {
            throw new RuntimeException('The entitlement grant is suspended.');
        }

        $policy = $this->policyRevision($tenantId, $policyRevisionKey, true);
        if ($policy === null) {
            try {
                (new EntitlementPolicyRevisionRecord())->save([
                    'tenant_id' => $tenantId,
                    'grant_id' => $grant->id,
                    'policy_revision_key' => $policyRevisionKey,
                    'meter_key' => $meterKey,
                    'unit_key' => $unitKey,
                    'limit_amount' => $limitAmount,
                    'period_kind' => $periodKind,
                    'effective_from' => $effectiveFrom,
                    'effective_until' => $effectiveUntil,
                    'reservation_ttl_seconds' => $reservationTtlSeconds,
                    'canonical_snapshot_json' => $canonicalSnapshotJson,
                    'canonical_snapshot_sha256' => $canonicalSnapshotSha256,
                    'created_by_member_id' => $memberId,
                    'created_at' => $now,
                ]);
            } catch (PDOException $exception) {
                if (!$this->isDuplicate($exception)) {
                    throw $exception;
                }
            }
            $policy = $this->policyRevision($tenantId, $policyRevisionKey, true)
                ?? throw new RuntimeException('The entitlement policy revision could not be read back.');
        }

        $this->assertSamePolicySnapshot(
            $policy,
            $grant->id,
            $meterKey,
            $unitKey,
            $limitAmount,
            $periodKind,
            $effectiveFrom,
            $effectiveUntil,
            $reservationTtlSeconds,
            $canonicalSnapshotJson,
            $canonicalSnapshotSha256,
        );

        if ($grant->currentPolicyRevisionId !== $policy->id) {
            $updated = EntitlementGrantRecord::scope('tenant', $this->scope($tenantId))
                ->where('id', $grant->id)
                ->where('revision', $grant->revision)
                ->update([
                    'current_policy_revision_id' => $policy->id,
                    'revision' => new Raw('revision + 1'),
                    'updated_by_member_id' => $memberId,
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new RuntimeException('The entitlement grant revision has changed.');
            }
        }

        return $policy;
    }

    public function policyRevision(
        int $tenantId,
        string $policyRevisionKey,
        bool $forUpdate = false,
    ): ?EntitlementPolicyRevision {
        $query = EntitlementPolicyRevisionRecord::scope('tenant', $this->scope($tenantId))
            ->where('policy_revision_key', $policyRevisionKey);
        if ($forUpdate) {
            $query->lock(true);
        }

        return $this->mapOne($query->find(), EntitlementPolicyRevision::fromRow(...));
    }

    public function policyRevisionById(
        int $tenantId,
        int $policyRevisionId,
        bool $forUpdate = false,
    ): ?EntitlementPolicyRevision {
        $query = EntitlementPolicyRevisionRecord::scope('tenant', $this->scope($tenantId))
            ->where('id', $policyRevisionId);
        if ($forUpdate) {
            $query->lock(true);
        }

        return $this->mapOne($query->find(), EntitlementPolicyRevision::fromRow(...));
    }

    public function usageWindow(
        int $tenantId,
        int $policyRevisionId,
        string $meterKey,
        string $targetType,
        string $targetKey,
        string $windowStart,
        bool $forUpdate = false,
    ): ?EntitlementUsageWindow {
        $query = EntitlementUsageWindowRecord::scope('tenant', $this->scope($tenantId))
            ->where('policy_revision_id', $policyRevisionId)
            ->where('meter_key', $meterKey)
            ->where('target_type', $targetType)
            ->where('target_key', $targetKey)
            ->where('window_start', $windowStart);
        if ($forUpdate) {
            $query->lock(true);
        }

        return $this->mapOne($query->find(), EntitlementUsageWindow::fromRow(...));
    }

    public function lockOrCreateUsageWindow(
        int $tenantId,
        int $policyRevisionId,
        string $meterKey,
        string $targetType,
        string $targetKey,
        string $windowStart,
        string $windowEnd,
        string $now,
    ): EntitlementUsageWindow {
        $this->assertCallerOwnedTransaction();
        $policy = $this->policyRevisionById($tenantId, $policyRevisionId)
            ?? throw new RuntimeException('The entitlement policy revision is unavailable.');
        if ($policy->meterKey !== $meterKey
            || $windowStart < $policy->effectiveFrom
            || $windowEnd > $policy->effectiveUntil) {
            throw new UnexpectedValueException('The entitlement usage window is outside its policy snapshot.');
        }
        $window = $this->usageWindow(
            $tenantId,
            $policyRevisionId,
            $meterKey,
            $targetType,
            $targetKey,
            $windowStart,
            true,
        );
        if ($window === null) {
            try {
                (new EntitlementUsageWindowRecord())->save([
                    'tenant_id' => $tenantId,
                    'policy_revision_id' => $policyRevisionId,
                    'meter_key' => $meterKey,
                    'target_type' => $targetType,
                    'target_key' => $targetKey,
                    'window_start' => $windowStart,
                    'window_end' => $windowEnd,
                    'committed_amount' => 0,
                    'revision' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (PDOException $exception) {
                if (!$this->isDuplicate($exception)) {
                    throw $exception;
                }
            }
            $window = $this->usageWindow(
                $tenantId,
                $policyRevisionId,
                $meterKey,
                $targetType,
                $targetKey,
                $windowStart,
                true,
            ) ?? throw new RuntimeException('The entitlement usage window could not be read back.');
        }
        if ($window->windowEnd !== $windowEnd) {
            throw new UnexpectedValueException('The entitlement usage window boundary is inconsistent.');
        }

        return $window;
    }

    public function livePendingAmount(int $tenantId, int $usageWindowId, string $now): int
    {
        $amount = EntitlementReservationRecord::scope('tenant', $this->scope($tenantId))
            ->where('usage_window_id', $usageWindowId)
            ->where('state', 'pending')
            ->where('expires_at', '>', $now)
            ->sum('amount');

        return $this->databaseInteger($amount);
    }

    public function createReservation(
        int $tenantId,
        int $usageWindowId,
        string $reservationKey,
        int $amount,
        int $limitAmount,
        int $memberId,
        string $reservedAt,
        string $expiresAt,
    ): EntitlementReservation {
        $this->assertCallerOwnedTransaction();
        if ($amount < 1 || $limitAmount < 1) {
            throw new InvalidArgumentException('Entitlement reservation amounts must be positive integers.');
        }
        $window = $this->usageWindowById($tenantId, $usageWindowId, true)
            ?? throw new RuntimeException('The entitlement usage window is unavailable.');
        $policy = $this->policyRevisionById($tenantId, $window->policyRevisionId)
            ?? throw new RuntimeException('The entitlement policy revision is unavailable.');
        if ($policy->meterKey !== $window->meterKey || $policy->limitAmount !== $limitAmount) {
            throw new UnexpectedValueException('The entitlement usage window policy is inconsistent.');
        }
        if ($expiresAt > $window->windowEnd) {
            throw new UnexpectedValueException('The reservation expiry exceeds its usage window.');
        }

        $this->expirePendingReservations($tenantId, $window, $memberId, $reservedAt);
        $pendingAmount = $this->livePendingAmount($tenantId, $window->id, $reservedAt);
        $requiredAmount = $this->checkedAdd(
            $this->checkedAdd($window->committedAmount, $pendingAmount),
            $amount,
        );
        if ($requiredAmount > $limitAmount) {
            throw new RuntimeException('The entitlement quota capacity is exceeded.');
        }

        try {
            (new EntitlementReservationRecord())->save([
                'tenant_id' => $tenantId,
                'usage_window_id' => $window->id,
                'reservation_key' => $reservationKey,
                'meter_key' => $window->meterKey,
                'target_type' => $window->targetType,
                'target_key' => $window->targetKey,
                'amount' => $amount,
                'state' => 'pending',
                'revision' => 1,
                'created_by_member_id' => $memberId,
                'settled_by_member_id' => null,
                'reserved_at' => $reservedAt,
                'expires_at' => $expiresAt,
                'settled_at' => null,
            ]);
        } catch (PDOException $exception) {
            if ($this->isDuplicate($exception)) {
                throw new RuntimeException('The entitlement reservation identity already exists.');
            }
            throw $exception;
        }

        $reservation = $this->reservation($tenantId, $reservationKey, true)
            ?? throw new RuntimeException('The entitlement reservation could not be read back.');
        $this->appendLedger($reservation, 'reserved', $memberId, $reservedAt);

        return $reservation;
    }

    public function reservation(
        int $tenantId,
        string $reservationKey,
        bool $forUpdate = false,
    ): ?EntitlementReservation {
        $query = EntitlementReservationRecord::scope('tenant', $this->scope($tenantId))
            ->where('reservation_key', $reservationKey);
        if ($forUpdate) {
            $query->lock(true);
        }

        return $this->mapOne($query->find(), EntitlementReservation::fromRow(...));
    }

    public function settleReservation(
        int $tenantId,
        string $reservationKey,
        string $settlementState,
        int $memberId,
        string $now,
    ): EntitlementReservation {
        $this->assertCallerOwnedTransaction();
        if (!in_array($settlementState, ['committed', 'released'], true)) {
            throw new InvalidArgumentException('The entitlement settlement state is invalid.');
        }

        $unlocked = $this->reservation($tenantId, $reservationKey)
            ?? throw new RuntimeException('The entitlement reservation is unavailable.');
        $window = $this->usageWindowById($tenantId, $unlocked->usageWindowId, true)
            ?? throw new RuntimeException('The entitlement usage window is unavailable.');
        $reservation = $this->reservation($tenantId, $reservationKey, true)
            ?? throw new RuntimeException('The entitlement reservation is unavailable.');
        if ($reservation->usageWindowId !== $window->id) {
            throw new UnexpectedValueException('The entitlement reservation window is inconsistent.');
        }
        if ($reservation->isTerminal()) {
            return $reservation;
        }

        $terminalState = $reservation->expiresAt <= $now ? 'expired' : $settlementState;
        if ($terminalState === 'committed') {
            $updated = EntitlementUsageWindowRecord::scope('tenant', $this->scope($tenantId))
                ->where('id', $window->id)
                ->where('revision', $window->revision)
                ->where('committed_amount', '<=', PHP_INT_MAX - $reservation->amount)
                ->update([
                    'committed_amount' => new Raw('committed_amount + ' . $reservation->amount),
                    'revision' => new Raw('revision + 1'),
                    'updated_at' => $now,
                ]);
            if ($updated !== 1) {
                throw new UnexpectedValueException('The entitlement committed amount overflowed or changed.');
            }
        }

        $this->transitionReservation($reservation, $terminalState, $memberId, $now);

        return $this->reservation($tenantId, $reservationKey, true)
            ?? throw new RuntimeException('The settled entitlement reservation could not be read back.');
    }

    public function usageWindowById(
        int $tenantId,
        int $usageWindowId,
        bool $forUpdate = false,
    ): ?EntitlementUsageWindow {
        $query = EntitlementUsageWindowRecord::scope('tenant', $this->scope($tenantId))
            ->where('id', $usageWindowId);
        if ($forUpdate) {
            $query->lock(true);
        }

        return $this->mapOne($query->find(), EntitlementUsageWindow::fromRow(...));
    }

    private function expirePendingReservations(
        int $tenantId,
        EntitlementUsageWindow $window,
        int $memberId,
        string $now,
    ): void {
        $records = EntitlementReservationRecord::scope('tenant', $this->scope($tenantId))
            ->where('usage_window_id', $window->id)
            ->where('state', 'pending')
            ->where('expires_at', '<=', $now)
            ->order('id')
            ->lock(true)
            ->select()
            ->toArray();
        foreach ($records as $record) {
            $this->transitionReservation(EntitlementReservation::fromRow($record), 'expired', $memberId, $now);
        }
    }

    private function transitionReservation(
        EntitlementReservation $reservation,
        string $state,
        int $memberId,
        string $now,
    ): void {
        $updated = EntitlementReservationRecord::scope('tenant', $this->scope($reservation->tenantId))
            ->where('id', $reservation->id)
            ->where('state', 'pending')
            ->where('revision', $reservation->revision)
            ->update([
                'state' => $state,
                'revision' => new Raw('revision + 1'),
                'settled_by_member_id' => $memberId,
                'settled_at' => $now,
            ]);
        if ($updated !== 1) {
            throw new RuntimeException('The entitlement reservation revision has changed.');
        }
        $this->appendLedger($reservation, $state, $memberId, $now);
    }

    private function appendLedger(
        EntitlementReservation $reservation,
        string $eventType,
        int $memberId,
        string $occurredAt,
    ): void {
        (new EntitlementUsageLedgerRecord())->save([
            'tenant_id' => $reservation->tenantId,
            'usage_window_id' => $reservation->usageWindowId,
            'reservation_id' => $reservation->id,
            'event_key' => hash('sha256', implode('|', [
                (string) $reservation->tenantId,
                $reservation->reservationKey,
                $eventType,
            ])),
            'event_type' => $eventType,
            'amount' => $reservation->amount,
            'actor_member_id' => $memberId,
            'occurred_at' => $occurredAt,
        ]);
    }

    private function assertSamePolicySnapshot(
        EntitlementPolicyRevision $policy,
        int $grantId,
        string $meterKey,
        string $unitKey,
        int $limitAmount,
        string $periodKind,
        string $effectiveFrom,
        string $effectiveUntil,
        int $reservationTtlSeconds,
        string $canonicalSnapshotJson,
        string $canonicalSnapshotSha256,
    ): void {
        if ($policy->grantId !== $grantId
            || $policy->meterKey !== $meterKey
            || $policy->unitKey !== $unitKey
            || $policy->limitAmount !== $limitAmount
            || $policy->periodKind !== $periodKind
            || $policy->effectiveFrom !== $effectiveFrom
            || $policy->effectiveUntil !== $effectiveUntil
            || $policy->reservationTtlSeconds !== $reservationTtlSeconds
            || $policy->canonicalSnapshotJson !== $canonicalSnapshotJson
            || !hash_equals($policy->canonicalSnapshotSha256, $canonicalSnapshotSha256)) {
            throw new UnexpectedValueException('The entitlement policy revision was reused with different bytes.');
        }
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($left < 0 || $right < 0 || $left > PHP_INT_MAX - $right) {
            throw new UnexpectedValueException('The entitlement usage amount overflowed.');
        }

        return $left + $right;
    }

    private function databaseInteger(mixed $value): int
    {
        $encoded = (string) $value;
        if (!preg_match('/^(0|[1-9][0-9]*)$/', $encoded)
            || strlen($encoded) > strlen((string) PHP_INT_MAX)
            || (strlen($encoded) === strlen((string) PHP_INT_MAX)
                && strcmp($encoded, (string) PHP_INT_MAX) > 0)) {
            throw new UnexpectedValueException('The entitlement usage amount is outside signed 64-bit range.');
        }

        return (int) $encoded;
    }

    private function assertCallerOwnedTransaction(): void
    {
        $connection = Db::connect();
        if (!$connection instanceof PDOConnection) {
            throw new RuntimeException('Entitlement quota writes require ThinkPHP PDO transaction support.');
        }
        $pdo = $connection->connect();
        if (!$pdo instanceof PDO || !$pdo->inTransaction()) {
            throw new RuntimeException('Entitlement quota writes require a caller-owned transaction.');
        }
    }

    private function scope(int $tenantId): TenantScope
    {
        return TenantScope::fromTrustedContext($tenantId, 'entitlement-quota');
    }

    /**
     * @template T
     * @param callable(array<string, mixed>): T $mapper
     * @return T|null
     */
    private function mapOne(?Model $record, callable $mapper): mixed
    {
        // Integrity-protected snapshot bytes must bypass presentation casts and accessors.
        return $record === null ? null : $mapper($record->getData());
    }

    private function isDuplicate(PDOException $exception): bool
    {
        $error = $exception->getData()['PDO Error Info'] ?? [];

        return (string) ($error['SQLSTATE'] ?? $exception->getCode()) === '23000'
            && (int) ($error['Driver Error Code'] ?? 0) === 1062;
    }
}
