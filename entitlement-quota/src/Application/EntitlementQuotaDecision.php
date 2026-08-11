<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Application;

final readonly class EntitlementQuotaDecision
{
    public function __construct(
        public bool $allowed,
        public string $meterKey,
        public string $targetType,
        public string $targetKey,
        public string $unitKey,
        public int $amount,
        public int $committedAmount,
        public int $reservedAmount,
        public int $limitAmount,
        public int $remainingAmount,
        public string $windowStart,
        public string $windowEnd,
        public string $policySnapshotSha256,
    ) {}

    /** @return array<string, bool|int|string> */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'meter_key' => $this->meterKey,
            'target_type' => $this->targetType,
            'target_key' => $this->targetKey,
            'unit_key' => $this->unitKey,
            'amount' => $this->amount,
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
