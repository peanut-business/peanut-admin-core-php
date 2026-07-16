<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Authorization;

use PDO;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\DataPermission\Target\ResolvedResourceTargets;
use PeanutAdmin\DataPermission\Target\ResourceTargetResolver;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PeanutAdmin\Kernel\Auth\TenantContext;

final readonly class FixtureTargetResolver implements ResourceTargetResolver
{
    public function __construct(private PDO $pdo, private string $targetResourceKey) {}

    public function resolveAndValidate(
        TenantContext $context,
        TypedResourceTargetSet $targets,
    ): ResolvedResourceTargets {
        if ($targets->targetResourceKey !== $this->targetResourceKey) {
            throw new DataAuthorizationException('AUTHZ_TARGET_TYPE_MISMATCH', 'Target type mismatch.');
        }
        $table = $this->targetResourceKey === 'fixture.project' ? 'fixture_project' : 'fixture_queue';
        $placeholders = implode(', ', array_fill(0, count($targets->targetIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id FROM {$table} WHERE tenant_id = ? AND id IN ({$placeholders})",
        );
        $statement->execute([$context->tenantId, ...$targets->targetIds]);
        if (count($statement->fetchAll(PDO::FETCH_COLUMN)) !== count($targets->targetIds)) {
            throw new DataAuthorizationException(
                'AUTHZ_TARGET_TENANT_MISMATCH',
                'The target does not belong to the current tenant.',
            );
        }

        return new ResolvedResourceTargets(new TypedResourceTargetCollection([$targets]));
    }
}
