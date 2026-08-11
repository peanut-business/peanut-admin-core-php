<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Contract;

final readonly class EntitlementMeter
{
    public function __construct(
        public string $meterKey,
        public string $targetType,
        public string $unitKey,
    ) {}

    /** @return array{meter_key: string, target_type: string, unit_key: string} */
    public function toArray(): array
    {
        return [
            'meter_key' => $this->meterKey,
            'target_type' => $this->targetType,
            'unit_key' => $this->unitKey,
        ];
    }
}
