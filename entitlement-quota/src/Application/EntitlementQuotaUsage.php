<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Application;

final readonly class EntitlementQuotaUsage
{
    public function __construct(
        public string $meterKey,
        public string $targetType,
        public string $targetKey,
        public string $unitKey,
        public int $committedAmount,
        public int $reservedAmount,
        public int $limitAmount,
        public int $remainingAmount,
        public string $windowStart,
        public string $windowEnd,
        public string $policySnapshotSha256,
    ) {}

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'meter_key' => $this->meterKey,
            'target_type' => $this->targetType,
            'target_key' => $this->targetKey,
            'unit_key' => $this->unitKey,
            'committed_amount' => $this->committedAmount,
            'reserved_amount' => $this->reservedAmount,
            'limit_amount' => $this->limitAmount,
            'remaining_amount' => $this->remainingAmount,
            'window_start' => $this->windowStart,
            'window_end' => $this->windowEnd,
            'policy_snapshot_sha256' => $this->policySnapshotSha256,
        ];
    }
}
