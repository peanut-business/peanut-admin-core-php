<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Persistence;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use PeanutAdmin\EntitlementQuota\Model\EntitlementGrant;
use PeanutAdmin\EntitlementQuota\Model\EntitlementPolicyRevision;
use PeanutAdmin\EntitlementQuota\Model\EntitlementReservation;
use PeanutAdmin\EntitlementQuota\Model\EntitlementUsageWindow;
use RuntimeException;
use UnexpectedValueException;

final readonly class PdoEntitlementQuotaRepository implements EntitlementQuotaRepository
{
    public function __construct(private PDO $pdo) {}

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function grant(int $tenantId, string $grantKey, bool $forUpdate = false): ?EntitlementGrant
    {
        $row = $this->fetchOne(<<<'SQL'
SELECT * FROM pa_entitlement_grant
WHERE tenant_id = :tenant_id AND grant_key = :grant_key
SQL . ($forUpdate ? ' FOR UPDATE' : ''), [
            'tenant_id' => $tenantId,
            'grant_key' => $grantKey,
        ]);

        return $row === null ? null : EntitlementGrant::fromRow($row);
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
        $this->requireTransaction();
        if (!hash_equals($canonicalSnapshotSha256, hash('sha256', $canonicalSnapshotJson))) {
            throw new UnexpectedValueException('Entitlement policy snapshot digest is invalid.');
        }

        $grant = $this->grant($tenantId, $grantKey, true);
        if ($grant === null) {
            try {
                $this->execute(<<<'SQL'
INSERT INTO pa_entitlement_grant (
  tenant_id, grant_key, state, current_policy_revision_id, revision,
  created_by_member_id, updated_by_member_id, created_at, updated_at
) VALUES (
  :tenant_id, :grant_key, 'active', NULL, 1,
  :created_member_id, :updated_member_id, :created_at, :updated_at
)
SQL, [
                    'tenant_id' => $tenantId,
                    'grant_key' => $grantKey,
                    'created_member_id' => $memberId,
                    'updated_member_id' => $memberId,
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
                $this->execute(<<<'SQL'
INSERT INTO pa_entitlement_policy_revision (
  tenant_id, grant_id, policy_revision_key, meter_key, unit_key, limit_amount,
  period_kind, effective_from, effective_until, reservation_ttl_seconds,
  canonical_snapshot_json, canonical_snapshot_sha256,
  created_by_member_id, created_at
) VALUES (
  :tenant_id, :grant_id, :policy_revision_key, :meter_key, :unit_key, :limit_amount,
  :period_kind, :effective_from, :effective_until, :reservation_ttl_seconds,
  :canonical_snapshot_json, :canonical_snapshot_sha256,
  :created_by_member_id, :created_at
)
SQL, [
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
            $updated = $this->execute(<<<'SQL'
UPDATE pa_entitlement_grant
SET current_policy_revision_id = :policy_revision_id,
    revision = revision + 1,
    updated_by_member_id = :member_id,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :grant_id AND revision = :expected_revision
SQL, [
                'policy_revision_id' => $policy->id,
                'member_id' => $memberId,
                'updated_at' => $now,
                'tenant_id' => $tenantId,
                'grant_id' => $grant->id,
                'expected_revision' => $grant->revision,
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
        $row = $this->fetchOne(<<<'SQL'
SELECT * FROM pa_entitlement_policy_revision
WHERE tenant_id = :tenant_id AND policy_revision_key = :policy_revision_key
SQL . ($forUpdate ? ' FOR UPDATE' : ''), [
            'tenant_id' => $tenantId,
            'policy_revision_key' => $policyRevisionKey,
        ]);

        return $row === null ? null : EntitlementPolicyRevision::fromRow($row);
    }

    public function policyRevisionById(
        int $tenantId,
        int $policyRevisionId,
        bool $forUpdate = false,
    ): ?EntitlementPolicyRevision {
        $row = $this->fetchOne(<<<'SQL'
SELECT * FROM pa_entitlement_policy_revision
WHERE tenant_id = :tenant_id AND id = :policy_revision_id
SQL . ($forUpdate ? ' FOR UPDATE' : ''), [
            'tenant_id' => $tenantId,
            'policy_revision_id' => $policyRevisionId,
        ]);

        return $row === null ? null : EntitlementPolicyRevision::fromRow($row);
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
        $row = $this->fetchOne(<<<'SQL'
SELECT * FROM pa_entitlement_usage_window
WHERE tenant_id = :tenant_id
  AND policy_revision_id = :policy_revision_id
  AND meter_key = :meter_key
  AND target_type = :target_type
  AND target_key = :target_key
  AND window_start = :window_start
SQL . ($forUpdate ? ' FOR UPDATE' : ''), [
            'tenant_id' => $tenantId,
            'policy_revision_id' => $policyRevisionId,
            'meter_key' => $meterKey,
            'target_type' => $targetType,
            'target_key' => $targetKey,
            'window_start' => $windowStart,
        ]);

        return $row === null ? null : EntitlementUsageWindow::fromRow($row);
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
        $this->requireTransaction();
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
                $this->execute(<<<'SQL'
INSERT INTO pa_entitlement_usage_window (
  tenant_id, policy_revision_id, meter_key, target_type, target_key,
  window_start, window_end, committed_amount, revision, created_at, updated_at
) VALUES (
  :tenant_id, :policy_revision_id, :meter_key, :target_type, :target_key,
  :window_start, :window_end, 0, 1, :created_at, :updated_at
)
SQL, [
                    'tenant_id' => $tenantId,
                    'policy_revision_id' => $policyRevisionId,
                    'meter_key' => $meterKey,
                    'target_type' => $targetType,
                    'target_key' => $targetKey,
                    'window_start' => $windowStart,
                    'window_end' => $windowEnd,
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
        $row = $this->fetchOne(<<<'SQL'
SELECT COALESCE(SUM(amount), 0) AS pending_amount
FROM pa_entitlement_reservation
WHERE tenant_id = :tenant_id AND usage_window_id = :usage_window_id
  AND state = 'pending' AND expires_at > :comparison_time
SQL, [
            'tenant_id' => $tenantId,
            'usage_window_id' => $usageWindowId,
            'comparison_time' => $now,
        ]);

        return $this->databaseInteger($row['pending_amount'] ?? 0);
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
        $this->requireTransaction();
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
        $usedAmount = $this->checkedAdd($window->committedAmount, $pendingAmount);
        $requiredAmount = $this->checkedAdd($usedAmount, $amount);
        if ($requiredAmount > $limitAmount) {
            throw new RuntimeException('The entitlement quota capacity is exceeded.');
        }

        try {
            $this->execute(<<<'SQL'
INSERT INTO pa_entitlement_reservation (
  tenant_id, usage_window_id, reservation_key, meter_key, target_type,
  target_key, amount, state, revision, created_by_member_id,
  settled_by_member_id, reserved_at, expires_at, settled_at
) VALUES (
  :tenant_id, :usage_window_id, :reservation_key, :meter_key, :target_type,
  :target_key, :amount, 'pending', 1, :created_by_member_id,
  NULL, :reserved_at, :expires_at, NULL
)
SQL, [
                'tenant_id' => $tenantId,
                'usage_window_id' => $window->id,
                'reservation_key' => $reservationKey,
                'meter_key' => $window->meterKey,
                'target_type' => $window->targetType,
                'target_key' => $window->targetKey,
                'amount' => $amount,
                'created_by_member_id' => $memberId,
                'reserved_at' => $reservedAt,
                'expires_at' => $expiresAt,
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
        $row = $this->fetchOne(<<<'SQL'
SELECT * FROM pa_entitlement_reservation
WHERE tenant_id = :tenant_id AND reservation_key = :reservation_key
SQL . ($forUpdate ? ' FOR UPDATE' : ''), [
            'tenant_id' => $tenantId,
            'reservation_key' => $reservationKey,
        ]);

        return $row === null ? null : EntitlementReservation::fromRow($row);
    }

    public function settleReservation(
        int $tenantId,
        string $reservationKey,
        string $settlementState,
        int $memberId,
        string $now,
    ): EntitlementReservation {
        $this->requireTransaction();
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
            $maximumPrevious = PHP_INT_MAX - $reservation->amount;
            $updatedWindow = $this->execute(<<<'SQL'
UPDATE pa_entitlement_usage_window
SET committed_amount = committed_amount + :amount,
    revision = revision + 1,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :usage_window_id
  AND revision = :expected_revision AND committed_amount <= :maximum_previous
SQL, [
                'amount' => $reservation->amount,
                'updated_at' => $now,
                'tenant_id' => $tenantId,
                'usage_window_id' => $window->id,
                'expected_revision' => $window->revision,
                'maximum_previous' => $maximumPrevious,
            ]);
            if ($updatedWindow !== 1) {
                throw new UnexpectedValueException('The entitlement committed amount overflowed or changed.');
            }
        }

        $this->transitionReservation($reservation, $terminalState, $memberId, $now);

        return $this->reservation($tenantId, $reservationKey, true)
            ?? throw new RuntimeException('The settled entitlement reservation could not be read back.');
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

    private function expirePendingReservations(
        int $tenantId,
        EntitlementUsageWindow $window,
        int $memberId,
        string $now,
    ): void {
        $statement = $this->statement(<<<'SQL'
SELECT * FROM pa_entitlement_reservation
WHERE tenant_id = :tenant_id AND usage_window_id = :usage_window_id
  AND state = 'pending' AND expires_at <= :comparison_time
ORDER BY id
FOR UPDATE
SQL);
        $statement->execute([
            'tenant_id' => $tenantId,
            'usage_window_id' => $window->id,
            'comparison_time' => $now,
        ]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row)) {
                throw new UnexpectedValueException('The entitlement reservation row is invalid.');
            }
            $this->transitionReservation(
                EntitlementReservation::fromRow($row),
                'expired',
                $memberId,
                $now,
            );
        }
    }

    private function transitionReservation(
        EntitlementReservation $reservation,
        string $state,
        int $memberId,
        string $now,
    ): void {
        $updated = $this->execute(<<<'SQL'
UPDATE pa_entitlement_reservation
SET state = :state, revision = revision + 1,
    settled_by_member_id = :member_id, settled_at = :settled_at
WHERE tenant_id = :tenant_id AND id = :reservation_id
  AND state = 'pending' AND revision = :expected_revision
SQL, [
            'state' => $state,
            'member_id' => $memberId,
            'settled_at' => $now,
            'tenant_id' => $reservation->tenantId,
            'reservation_id' => $reservation->id,
            'expected_revision' => $reservation->revision,
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
        $eventKey = hash('sha256', implode('|', [
            (string) $reservation->tenantId,
            $reservation->reservationKey,
            $eventType,
        ]));
        $this->execute(<<<'SQL'
INSERT INTO pa_entitlement_usage_ledger (
  tenant_id, usage_window_id, reservation_id, event_key,
  event_type, amount, actor_member_id, occurred_at
) VALUES (
  :tenant_id, :usage_window_id, :reservation_id, :event_key,
  :event_type, :amount, :actor_member_id, :occurred_at
)
SQL, [
            'tenant_id' => $reservation->tenantId,
            'usage_window_id' => $reservation->usageWindowId,
            'reservation_id' => $reservation->id,
            'event_key' => $eventKey,
            'event_type' => $eventType,
            'amount' => $reservation->amount,
            'actor_member_id' => $memberId,
            'occurred_at' => $occurredAt,
        ]);
    }

    public function usageWindowById(
        int $tenantId,
        int $usageWindowId,
        bool $forUpdate = false,
    ): ?EntitlementUsageWindow {
        $row = $this->fetchOne(<<<'SQL'
SELECT * FROM pa_entitlement_usage_window
WHERE tenant_id = :tenant_id AND id = :usage_window_id
SQL . ($forUpdate ? ' FOR UPDATE' : ''), [
            'tenant_id' => $tenantId,
            'usage_window_id' => $usageWindowId,
        ]);

        return $row === null ? null : EntitlementUsageWindow::fromRow($row);
    }

    /** @param array<string, int|string|null> $parameters */
    private function fetchOne(string $sql, array $parameters): ?array
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, int|string|null> $parameters */
    private function execute(string $sql, array $parameters): int
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    private function statement(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('Could not prepare entitlement quota statement.');
        }

        return $statement;
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

    private function isDuplicate(PDOException $exception): bool
    {
        return (string) ($exception->errorInfo[0] ?? $exception->getCode()) === '23000'
            && (int) ($exception->errorInfo[1] ?? 0) === 1062;
    }

    private function requireTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new RuntimeException('Entitlement quota writes require one caller-owned transaction.');
        }
    }
}
