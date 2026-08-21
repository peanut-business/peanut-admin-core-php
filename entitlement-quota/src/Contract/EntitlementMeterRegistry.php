<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Contract;

interface EntitlementMeterRegistry
{
    public function find(string $meterKey, string $targetType): ?EntitlementMeter;
}
