<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Model;

final readonly class EntitlementUsageWindow
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $policyRevisionId,
        public string $meterKey,
        public string $targetType,
        public string $targetKey,
        public string $windowStart,
        public string $windowEnd,
        public int $committedAmount,
        public int $revision,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['policy_revision_id'],
            (string) $row['meter_key'],
            (string) $row['target_type'],
            (string) $row['target_key'],
            (string) $row['window_start'],
            (string) $row['window_end'],
            (int) $row['committed_amount'],
            (int) $row['revision'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'policy_revision_id' => $this->policyRevisionId,
            'meter_key' => $this->meterKey,
            'target_type' => $this->targetType,
            'target_key' => $this->targetKey,
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
            'committed_amount' => $this->committedAmount,
            'revision' => $this->revision,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
