<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

use think\facade\Db;

final readonly class ThinkPhpDepartmentHierarchyProvider implements DepartmentHierarchyProvider
{
    public function descendantsIncludingSelf(int $tenantId, int $departmentId): array
    {
        $root = Db::name('department')
            ->where('tenant_id', $tenantId)
            ->where('id', $departmentId)
            ->where('status', 'active')
            ->value('id');
        if ($root === null) {
            return [];
        }

        $ids = [(int) $root];
        $frontier = $ids;
        for ($depth = 1; $depth < 10 && $frontier !== []; $depth++) {
            $frontier = array_map('intval', Db::name('department')
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->whereIn('parent_id', $frontier)
                ->column('id'));
            $ids = [...$ids, ...$frontier];
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
