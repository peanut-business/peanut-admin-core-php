<?php

declare(strict_types=1);

namespace PeanutAdmin\EntitlementQuota\Contract;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface EntitlementPolicyProvider
{
    public function snapshot(
        AuthorizedOperationContext $context,
        EntitlementMeter $meter,
        string $targetKey,
        DateTimeImmutable $evaluatedAt,
    ): ?EntitlementGrantSnapshot;
}
