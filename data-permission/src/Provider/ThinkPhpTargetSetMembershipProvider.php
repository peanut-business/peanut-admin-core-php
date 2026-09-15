<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

use PeanutAdmin\DataPermission\Model\DataPermissionTargetRecord;

final readonly class ThinkPhpTargetSetMembershipProvider implements TargetSetMembershipProvider
{
    public function containsAll(int $tenantId, int $targetSetId, array $targetIds): bool
    {
        $targetIds = array_values(array_unique($targetIds));
        if ($targetIds === []) {
            return true;
        }
        foreach (array_chunk($targetIds, 500) as $chunk) {
            $count = DataPermissionTargetRecord::where('tenant_id', $tenantId)
                ->where('target_set_id', $targetSetId)
                ->where('status', 'active')
                ->whereIn('target_id', $chunk)
                ->distinct(true)
                ->count('target_id');
            if ((int) $count !== count($chunk)) {
                return false;
            }
        }

        return true;
    }
}
