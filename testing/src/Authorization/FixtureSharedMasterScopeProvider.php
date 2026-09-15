<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Authorization;

use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Constraint\AlwaysFalse;
use PeanutAdmin\DataPermission\Constraint\ColumnIn;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Constraint\QueryConstraint;
use PeanutAdmin\DataPermission\Context\AuthorizationContext;
use PeanutAdmin\DataPermission\Decision\AuthorizationDecision;
use PeanutAdmin\DataPermission\Provider\SharedMasterScopeProvider;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use think\facade\Db;

final readonly class FixtureSharedMasterScopeProvider implements SharedMasterScopeProvider
{
    public function compileVisiblePredicate(
        AuthorizationContext $context,
        ResourceOperation $operation,
        TypedResourceTargetCollection $targets,
    ): QueryConstraint {
        $projectIds = Db::table('fixture_target_visibility')
            ->where('tenant_id', $context->tenant->tenantId)
            ->where('member_id', $context->tenant->memberId)
            ->column('target_id');
        $privateIds = $projectIds === [] ? [] : Db::table('fixture_reference_visibility')
            ->where('tenant_id', $context->tenant->tenantId)
            ->whereIn('project_id', $projectIds)
            ->column('reference_id');
        $ids = array_values(array_unique(array_map('strval', [
            ...Db::table('fixture_reference')->where('visibility', 'public')->column('id'),
            ...$privateIds,
        ])));
        sort($ids, SORT_STRING);
        if ($ids === []) {
            return new AlwaysFalse();
        }

        return new ColumnIn(new ColumnReference('reference.id'), $ids);
    }

    public function assertUsageAllowed(
        AuthorizationContext $context,
        ResourceOperation $operation,
        string $resourceId,
        TypedResourceTargetCollection $targets,
    ): AuthorizationDecision {
        $projectIds = [];
        foreach ($targets->sets as $set) {
            if ($set->targetResourceKey === 'fixture.project') {
                $projectIds = [...$projectIds, ...$set->targetIds];
            }
        }
        if (count($projectIds) !== 1) {
            return AuthorizationDecision::deny('AUTHZ_SHARED_SCOPE_DENIED');
        }
        $reference = Db::table('fixture_reference')->where('id', $resourceId)->find();
        $visible = is_array($reference) && (string) $reference['visibility'] === 'public'
            ? $resourceId
            : Db::table('fixture_reference_visibility')
                ->where('reference_id', $resourceId)
                ->where('tenant_id', $context->tenant->tenantId)
                ->where('project_id', $projectIds[0])
                ->value('reference_id');

        return $visible === null
            ? AuthorizationDecision::deny('AUTHZ_SHARED_SCOPE_DENIED')
            : AuthorizationDecision::allow();
    }
}
