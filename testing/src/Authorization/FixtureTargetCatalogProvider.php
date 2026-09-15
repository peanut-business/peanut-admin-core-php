<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Authorization;

use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Context\AuthorizationContext;
use PeanutAdmin\DataPermission\Policy\EffectivePolicySet;
use PeanutAdmin\DataPermission\Target\ResourceTargetCatalogProvider;
use PeanutAdmin\DataPermission\Target\TargetCatalogQuery;
use PeanutAdmin\DataPermission\Target\TargetOptionPage;
use think\facade\Db;

final readonly class FixtureTargetCatalogProvider implements ResourceTargetCatalogProvider
{
    public function searchAllowedTargets(
        AuthorizationContext $context,
        ResourceOperation $operation,
        TargetCatalogQuery $query,
        EffectivePolicySet $policies,
    ): TargetOptionPage {
        $visibleIds = Db::table('fixture_target_visibility')
            ->where('tenant_id', $context->tenant->tenantId)
            ->where('member_id', $context->tenant->memberId)
            ->column('target_id');
        if ($visibleIds === []) {
            return new TargetOptionPage([], 0);
        }
        $targets = Db::table('fixture_project')
            ->where('tenant_id', $context->tenant->tenantId)
            ->whereIn('id', $visibleIds)
            ->whereLike('name', '%' . $query->search . '%');
        $total = $targets->count();
        $rows = $targets->field(['id', 'name'])
            ->order('id')
            ->page($query->page, $query->pageSize)
            ->select()
            ->toArray();
        $items = array_values(array_map(
            static fn(array $row): array => ['id' => (string) $row['id'], 'label' => (string) $row['name']],
            $rows,
        ));

        return new TargetOptionPage($items, $total);
    }
}
