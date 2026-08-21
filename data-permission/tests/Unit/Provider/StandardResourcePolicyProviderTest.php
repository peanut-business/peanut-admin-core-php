<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Unit\Provider;

use DateTimeImmutable;
use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Context\AuthorizationContext;
use PeanutAdmin\DataPermission\Policy\EffectiveCondition;
use PeanutAdmin\DataPermission\Policy\EffectiveConditionGroup;
use PeanutAdmin\DataPermission\Policy\EffectivePolicySet;
use PeanutAdmin\DataPermission\Provider\DepartmentHierarchyProvider;
use PeanutAdmin\DataPermission\Provider\ProviderColumnMap;
use PeanutAdmin\DataPermission\Provider\StandardResourcePolicyProvider;
use PeanutAdmin\DataPermission\Provider\TargetSetMembershipProvider;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PHPUnit\Framework\TestCase;

final class StandardResourcePolicyProviderTest extends TestCase
{
    public function testEveryRequestedTargetSetMustBeCoveredByTheSamePolicyGroup(): void
    {
        $provider = new StandardResourcePolicyProvider(
            new ProviderColumnMap(
                new ColumnReference('resource.tenant_id'),
                targetColumns: [
                    'example.project' => new ColumnReference('resource.project_id'),
                    'example.queue' => new ColumnReference('resource.queue_id'),
                ],
            ),
            new class implements DepartmentHierarchyProvider {
                public function descendantsIncludingSelf(int $tenantId, int $departmentId): array
                {
                    return [];
                }
            },
            new class implements TargetSetMembershipProvider {
                public function containsAll(int $tenantId, int $targetSetId, array $targetIds): bool
                {
                    return false;
                }
            },
        );
        $context = new AuthorizationContext(TenantContext::fromValidatedSession(
            new ValidatedTenantSession(
                1,
                'session',
                10,
                20,
                30,
                'admin-web',
                new DateTimeImmutable('2026-07-16T10:00:00Z'),
                1,
            ),
            'request-policy-coverage',
        ), null);
        $operation = new ResourceOperation(
            1,
            1,
            'example.work-item',
            'example.work-item',
            'provider',
            'business_target_owned',
            'transfer',
            'explicit_targets',
            'one_required',
            'all',
            ['example.work-item.transfer'],
            [],
        );
        $targets = new TypedResourceTargetCollection([
            new TypedResourceTargetSet('example.project', ['101'], 'source'),
            new TypedResourceTargetSet('example.queue', ['202'], 'destination'),
        ]);
        $projectCondition = new EffectiveCondition(
            1,
            'core.specified_objects',
            1,
            'example.project',
            ['101'],
            1,
        );
        $queueCondition = new EffectiveCondition(
            2,
            'core.specified_objects',
            2,
            'example.queue',
            ['202'],
            1,
        );

        $projectOnly = new EffectivePolicySet([
            new EffectiveConditionGroup(1, 1, 1, [$projectCondition]),
        ], null);
        self::assertFalse($provider->assertTargetsAllowed(
            $context,
            $operation,
            $targets,
            $projectOnly,
        )->allowed);

        $fullyCovered = new EffectivePolicySet([
            new EffectiveConditionGroup(1, 1, 1, [$projectCondition, $queueCondition]),
        ], null);
        self::assertTrue($provider->assertTargetsAllowed(
            $context,
            $operation,
            $targets,
            $fullyCovered,
        )->allowed);
    }
}
