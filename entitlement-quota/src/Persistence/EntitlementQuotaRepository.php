<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Persistence;

use PDO;
use PeanutAdmin\EntitlementQuota\Model\EntitlementGrant;
use PeanutAdmin\EntitlementQuota\Model\EntitlementPolicyRevision;
use PeanutAdmin\EntitlementQuota\Model\EntitlementReservation;
use PeanutAdmin\EntitlementQuota\Model\EntitlementUsageWindow;

interface EntitlementQuotaRepository
{
    public function connection(): PDO;

    public function grant(int $tenantId, string $grantKey, bool $forUpdate = false): ?EntitlementGrant;

    /**
     * Lock the Tenant grant, persist or verify one immutable policy snapshot,
     * and advance the grant's current-policy pointer.
     */
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
    ): EntitlementPolicyRevision;

    public function policyRevision(
        int $tenantId,
        string $policyRevisionKey,
        bool $forUpdate = false,
    ): ?EntitlementPolicyRevision;

    public function policyRevisionById(
        int $tenantId,
        int $policyRevisionId,
        bool $forUpdate = false,
    ): ?EntitlementPolicyRevision;

    public function usageWindow(
        int $tenantId,
        int $policyRevisionId,
        string $meterKey,
        string $targetType,
        string $targetKey,
        string $windowStart,
        bool $forUpdate = false,
    ): ?EntitlementUsageWindow;

    public function usageWindowById(
        int $tenantId,
        int $usageWindowId,
        bool $forUpdate = false,
    ): ?EntitlementUsageWindow;

    /** Lock the existing UTC window or create and lock its exact identity. */
    public function lockOrCreateUsageWindow(
        int $tenantId,
        int $policyRevisionId,
        string $meterKey,
        string $targetType,
        string $targetKey,
        string $windowStart,
        string $windowEnd,
        string $now,
    ): EntitlementUsageWindow;

    /** Sum only unexpired pending reservations for a Tenant-owned window. */
    public function livePendingAmount(int $tenantId, int $usageWindowId, string $now): int;

    /**
     * Under the window lock, expire stale pending rows, prove capacity, create
     * one pending reservation and append its reserved ledger event.
     */
    public function createReservation(
        int $tenantId,
        int $usageWindowId,
        string $reservationKey,
        int $amount,
        int $limitAmount,
        int $memberId,
        string $reservedAt,
        string $expiresAt,
    ): EntitlementReservation;

    public function reservation(
        int $tenantId,
        string $reservationKey,
        bool $forUpdate = false,
    ): ?EntitlementReservation;

    /**
     * Settle a pending reservation as committed or released. An already
     * expired pending row is persisted as expired and returned as such.
     */
    public function settleReservation(
        int $tenantId,
        string $reservationKey,
        string $settlementState,
        int $memberId,
        string $now,
    ): EntitlementReservation;
}
