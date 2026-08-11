<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Model;

use UnexpectedValueException;

final readonly class EntitlementPolicyRevision
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $grantId,
        public string $policyRevisionKey,
        public string $meterKey,
        public string $unitKey,
        public int $limitAmount,
        public string $periodKind,
        public string $effectiveFrom,
        public string $effectiveUntil,
        public int $reservationTtlSeconds,
        public string $canonicalSnapshotJson,
        public string $canonicalSnapshotSha256,
        public int $createdByMemberId,
        public string $createdAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $model = new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['grant_id'],
            (string) $row['policy_revision_key'],
            (string) $row['meter_key'],
            (string) $row['unit_key'],
            (int) $row['limit_amount'],
            (string) $row['period_kind'],
            (string) $row['effective_from'],
            (string) $row['effective_until'],
            (int) $row['reservation_ttl_seconds'],
            (string) $row['canonical_snapshot_json'],
            (string) $row['canonical_snapshot_sha256'],
            (int) $row['created_by_member_id'],
            (string) $row['created_at'],
        );
        $model->assertSnapshotIntegrity();

        return $model;
    }

    public function assertSnapshotIntegrity(): void
    {
        if (!hash_equals($this->canonicalSnapshotSha256, hash('sha256', $this->canonicalSnapshotJson))) {
            throw new UnexpectedValueException('Entitlement policy snapshot digest is invalid.');
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'grant_id' => $this->grantId,
            'policy_revision_key' => $this->policyRevisionKey,
            'meter_key' => $this->meterKey,
            'unit_key' => $this->unitKey,
            'limit_amount' => $this->limitAmount,
            'period_kind' => $this->periodKind,
            'effective_from' => $this->effectiveFrom,
            'effective_until' => $this->effectiveUntil,
            'reservation_ttl_seconds' => $this->reservationTtlSeconds,
            'canonical_snapshot_json' => $this->canonicalSnapshotJson,
            'canonical_snapshot_sha256' => $this->canonicalSnapshotSha256,
            'created_by_member_id' => $this->createdByMemberId,
            'created_at' => $this->createdAt,
        ];
    }
}
