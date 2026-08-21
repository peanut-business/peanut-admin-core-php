<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Authorization;

use PDO;
use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Constraint\AlwaysFalse;
use PeanutAdmin\DataPermission\Constraint\ColumnIn;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Constraint\QueryConstraint;
use PeanutAdmin\DataPermission\Context\AuthorizationContext;
use PeanutAdmin\DataPermission\Decision\AuthorizationDecision;
use PeanutAdmin\DataPermission\Provider\SharedMasterScopeProvider;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;

final readonly class FixtureSharedMasterScopeProvider implements SharedMasterScopeProvider
{
    public function __construct(private PDO $pdo) {}

    public function compileVisiblePredicate(
        AuthorizationContext $context,
        ResourceOperation $operation,
        TypedResourceTargetCollection $targets,
    ): QueryConstraint {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT DISTINCT reference.id
FROM fixture_reference reference
LEFT JOIN fixture_reference_visibility visibility
  ON visibility.reference_id = reference.id
 AND visibility.tenant_id = :visibility_tenant_id
LEFT JOIN fixture_target_visibility target_visibility
  ON target_visibility.tenant_id = visibility.tenant_id
 AND target_visibility.target_id = visibility.project_id
 AND target_visibility.member_id = :member_id
WHERE reference.visibility = 'public'
   OR (visibility.tenant_id = :tenant_id AND target_visibility.member_id IS NOT NULL)
ORDER BY reference.id
SQL);
        $statement->execute([
            'visibility_tenant_id' => $context->tenant->tenantId,
            'member_id' => $context->tenant->memberId,
            'tenant_id' => $context->tenant->tenantId,
        ]);
        $ids = array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
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
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT reference.id
FROM fixture_reference reference
LEFT JOIN fixture_reference_visibility visibility
  ON visibility.reference_id = reference.id
 AND visibility.tenant_id = :tenant_id
 AND visibility.project_id = :project_id
WHERE reference.id = :reference_id
  AND (reference.visibility = 'public' OR visibility.reference_id IS NOT NULL)
LIMIT 1
SQL);
        $statement->execute([
            'tenant_id' => $context->tenant->tenantId,
            'project_id' => $projectIds[0],
            'reference_id' => $resourceId,
        ]);

        return $statement->fetchColumn() === false
            ? AuthorizationDecision::deny('AUTHZ_SHARED_SCOPE_DENIED')
            : AuthorizationDecision::allow();
    }
}
