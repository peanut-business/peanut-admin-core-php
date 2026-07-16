<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

interface TargetSetMembershipProvider
{
    /** @param list<string> $targetIds */
    public function containsAll(int $tenantId, int $targetSetId, array $targetIds): bool;
}
