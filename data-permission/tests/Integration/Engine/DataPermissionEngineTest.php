<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Integration\Engine;

use DateTimeImmutable;
use PDO;
use PeanutAdmin\DataPermission\Catalog\PdoResourceOperationCatalog;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Constraint\PdoQueryConstraintCompiler;
use PeanutAdmin\DataPermission\Engine\DataPermissionEngine;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\DataPermission\Policy\PdoPolicyRepository;
use PeanutAdmin\DataPermission\Policy\PolicyCache;
use PeanutAdmin\DataPermission\Provider\ConditionProviderRegistry;
use PeanutAdmin\DataPermission\Provider\PdoDepartmentHierarchyProvider;
use PeanutAdmin\DataPermission\Provider\PdoTargetSetMembershipProvider;
use PeanutAdmin\DataPermission\Provider\ProviderColumnMap;
use PeanutAdmin\DataPermission\Provider\ResourceProviderRegistry;
use PeanutAdmin\DataPermission\Provider\SharedMasterScopeProviderRegistry;
use PeanutAdmin\DataPermission\Provider\StandardResourcePolicyProvider;
use PeanutAdmin\DataPermission\Target\ResolvedResourceTargets;
use PeanutAdmin\DataPermission\Target\ResourceTargetResolver;
use PeanutAdmin\DataPermission\Target\TargetCatalogProviderRegistry;
use PeanutAdmin\DataPermission\Target\TargetResolverRegistry;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PeanutAdmin\DataPermission\Tests\Integration\Schema\DataPermissionMigrationRunner;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Kernel\Authorization\PdoTenantAuthorizationRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PdoAuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;
use PeanutAdmin\Kernel\Authorization\Persistence\ProtectedResourceDefinition;
use PeanutAdmin\Kernel\Authorization\Persistence\ResourceOperationDefinition;
use PeanutAdmin\Kernel\Authorization\Persistence\TargetTypeDefinition;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__, 4) . '/kernel/tests/Integration/Schema/DatabaseTestCase.php';
require_once dirname(__DIR__) . '/Schema/DataPermissionMigrationRunner.php';

final class DataPermissionEngineTest extends DatabaseTestCase
{
    private const NOW = '2026-07-16 10:00:00.000';

    private int $alphaTenant;
    private int $betaTenant;
    private int $accountId;
    private int $memberId;
    private int $otherMemberId;
    private int $roleA;
    private int $roleB;
    private int $resourceId;
    private int $targetTypeId;
    private int $readPermissionId;
    private int $updatePermissionId;

    /** @var array<string, int> */
    private array $conditionIds = [];

    private PdoAuthorizationCatalogRepository $authorizationCatalog;
    private DataPermissionEngine $engine;
    private TenantContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
        (new DataPermissionMigrationRunner(
            self::DATABASE,
            '127.0.0.1',
            (int) (getenv('MYSQL_PORT') ?: 3306),
            'root',
            getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev',
        ))->migrate();
        $this->createFixtureTables();
        $this->seedKernel();
        $this->seedBusinessFixtures();
        $this->engine = $this->engine();
    }

    public function testAllSixCoreScopesCompileIntoTheDatabaseQuery(): void
    {
        $cases = [
            'core.tenant_all' => [null, [1, 2, 3]],
            'core.self' => [null, [1]],
            'core.own_department' => [null, [1]],
            'core.department_tree' => [null, [1, 2]],
            'core.specified_departments' => [$this->targetSet('core.department', ['3']), [3]],
            'core.specified_objects' => [$this->targetSet('example.project', ['102']), [2]],
        ];

        foreach ($cases as $conditionKey => [$targetSetId, $expectedIds]) {
            $operation = 'scope-' . str_replace(['core.', '_'], ['', '-'], $conditionKey);
            $operationId = $this->operation($operation, 'rule_filtered', 'many_readable', $this->readPermissionId);
            $this->policy($this->roleA, $operationId, [[[$conditionKey, $targetSetId]]]);

            self::assertSame($expectedIds, $this->authorizedIds($operation), $conditionKey);
        }
    }

    public function testConditionsUseAndWhileGroupsAndRolesUseOr(): void
    {
        $operationId = $this->operation('combined', 'rule_filtered', 'many_readable', $this->readPermissionId);
        $projectA = $this->targetSet('example.project', ['101']);
        $projectB = $this->targetSet('example.project', ['102']);
        $siblingDepartment = $this->targetSet('core.department', ['3']);
        $this->policy($this->roleA, $operationId, [
            [['core.self', null], ['core.specified_objects', $projectA]],
            [['core.specified_departments', $siblingDepartment]],
        ]);
        $this->policy($this->roleB, $operationId, [
            [['core.specified_objects', $projectB]],
        ]);

        self::assertSame([1, 2, 3], $this->authorizedIds('combined'));
    }

    public function testNoPolicyUnknownOperationAndMissingFunctionalPermissionFailClosed(): void
    {
        $operationId = $this->operation('no-policy', 'rule_filtered', 'many_readable', $this->readPermissionId);
        self::assertSame([], $this->authorizedIds('no-policy'));

        $expiredPolicy = $this->policy($this->roleA, $operationId, [[['core.tenant_all', null]]]);
        $this->database->exec(<<<SQL
UPDATE pa_data_permission_policy
SET valid_until = '2020-01-01 00:00:00.000', revision = revision + 1
WHERE id = {$expiredPolicy}
SQL);
        self::assertSame([], $this->authorizedIds('no-policy'));

        $this->expectDataError('AUTHZ_OPERATION_UNDECLARED', fn() => $this->engine->queryConstraint(
            $this->context,
            'example.work-item',
            'not-declared',
        ));

        $otherContext = $this->context($this->otherMemberId, 2);
        $this->expectDataError('AUTHZ_PERMISSION_DENIED', fn() => $this->engine->queryConstraint(
            $otherContext,
            'example.work-item',
            'no-policy',
        ));
    }

    public function testPolicyRevisionMakesCachedCompiledScopeUnreachable(): void
    {
        $operationId = $this->operation('cache-revision', 'rule_filtered', 'many_readable', $this->readPermissionId);
        $projectA = $this->targetSet('example.project', ['101']);
        $policyId = $this->policy($this->roleA, $operationId, [[['core.specified_objects', $projectA]]]);
        self::assertSame([1], $this->authorizedIds('cache-revision'));

        $projectB = $this->targetSet('example.project', ['102']);
        $groupId = $this->insert('pa_data_permission_group', [
            'tenant_id' => $this->alphaTenant,
            'data_permission_policy_id' => $policyId,
            'name' => 'Added after cache',
            'sort_order' => 2,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_data_permission_condition', [
            'tenant_id' => $this->alphaTenant,
            'data_permission_group_id' => $groupId,
            'condition_definition_id' => $this->conditionIds['core.specified_objects'],
            'target_set_id' => $projectB,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->database->exec(<<<SQL
UPDATE pa_data_permission_policy SET revision = revision + 1 WHERE id = {$policyId}
SQL);

        self::assertSame([1, 2], $this->authorizedIds('cache-revision'));
    }

    public function testExplicitTargetsValidateCardinalityTypeTenantAndPolicy(): void
    {
        $operationId = $this->operation('update', 'explicit_targets', 'one_required', $this->updatePermissionId);
        $projectA = $this->targetSet('example.project', ['101']);
        $this->policy($this->roleA, $operationId, [[['core.specified_objects', $projectA]]]);

        $allowed = $this->engine->decideTargets(
            $this->context,
            'example.work-item',
            'update',
            $this->targets('example.project', ['101']),
        );
        self::assertTrue($allowed->allowed);
        self::assertFalse($this->engine->decideTargets(
            $this->context,
            'example.work-item',
            'update',
            $this->targets('example.project', ['102']),
        )->allowed);

        $this->expectDataError('AUTHZ_TARGET_CARDINALITY_INVALID', fn() => $this->engine->decideTargets(
            $this->context,
            'example.work-item',
            'update',
            new TypedResourceTargetCollection(),
        ));
        $this->expectDataError('AUTHZ_TARGET_CARDINALITY_INVALID', fn() => $this->engine->decideTargets(
            $this->context,
            'example.work-item',
            'update',
            $this->targets('example.project', ['101', '102']),
        ));
        $this->expectDataError('AUTHZ_TARGET_TYPE_MISMATCH', fn() => $this->engine->decideTargets(
            $this->context,
            'example.work-item',
            'update',
            $this->targets('example.queue', ['101']),
        ));
        $this->expectDataError('AUTHZ_TARGET_TENANT_MISMATCH', fn() => $this->engine->decideTargets(
            $this->context,
            'example.work-item',
            'update',
            $this->targets('example.project', ['201']),
        ));
    }

    public function testTenFiveHundredAndFiveThousandTargetsRemainSqlBounded(): void
    {
        $this->database->exec('DELETE FROM test_work_item');
        $insert = $this->database->prepare(<<<'SQL'
INSERT INTO test_work_item (id, tenant_id, created_by_member_id, department_id, project_id, name)
VALUES (:id, :tenant_id, :member_id, 1, :project_id, :name)
SQL);
        for ($id = 1; $id <= 5000; ++$id) {
            $insert->execute([
                'id' => $id,
                'tenant_id' => $this->alphaTenant,
                'member_id' => $this->memberId,
                'project_id' => $id,
                'name' => 'Item ' . $id,
            ]);
        }

        foreach ([10, 500, 5000] as $size) {
            $operation = 'target-size-' . $size;
            $operationId = $this->operation($operation, 'rule_filtered', 'many_readable', $this->readPermissionId);
            $targetIds = array_map('strval', range(1, $size));
            $targetSetId = $this->targetSet('example.project', $targetIds);
            $this->policy($this->roleA, $operationId, [[[
                'core.specified_objects',
                $targetSetId,
            ]]]);
            $compiled = (new PdoQueryConstraintCompiler())->compile($this->engine->queryConstraint(
                $this->context,
                'example.work-item',
                $operation,
            ));
            $statement = $this->database->prepare(
                'SELECT COUNT(*) FROM test_work_item work_item WHERE ' . $compiled->sql,
            );
            $statement->execute($compiled->parameters);
            self::assertSame($size, (int) $statement->fetchColumn());

            $explain = $this->database->prepare(
                'EXPLAIN SELECT id FROM test_work_item work_item WHERE ' . $compiled->sql,
            );
            $explain->execute($compiled->parameters);
            self::assertNotFalse($explain->fetch(PDO::FETCH_ASSOC));
            if ($size <= 500) {
                self::assertStringContainsString(' IN (', $compiled->sql);
            } else {
                self::assertStringContainsString('EXISTS (', $compiled->sql);
                self::assertLessThan(10, count($compiled->parameters));
                self::assertTrue((new PdoTargetSetMembershipProvider($this->database))->containsAll(
                    $this->alphaTenant,
                    $targetSetId,
                    $targetIds,
                ));
            }
        }
    }

    private function createFixtureTables(): void
    {
        $this->database->exec(<<<'SQL'
CREATE TABLE test_project (
    id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_project_tenant (tenant_id, id)
) ENGINE=InnoDB
SQL);
        $this->database->exec(<<<'SQL'
CREATE TABLE test_work_item (
    id BIGINT UNSIGNED NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    created_by_member_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_work_item_tenant_project (tenant_id, project_id, id),
    KEY idx_work_item_tenant_department (tenant_id, department_id, id)
) ENGINE=InnoDB
SQL);
    }

    private function seedKernel(): void
    {
        $this->authorizationCatalog = new PdoAuthorizationCatalogRepository($this->database);
        (new CorePermissionCatalogSynchronizer($this->authorizationCatalog))->synchronize();
        $this->insert('pa_module_installation', [
            'module_key' => 'example',
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => hash('sha256', 'example'),
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->readPermissionId = $this->authorizationCatalog->syncPermission(new PermissionDefinition(
            'example.work-item.read',
            'example',
            'api',
            'Read work items',
            'normal',
            '1.0.0',
        ));
        $this->updatePermissionId = $this->authorizationCatalog->syncPermission(new PermissionDefinition(
            'example.work-item.update',
            'example',
            'api',
            'Update work items',
            'sensitive',
            '1.0.0',
        ));
        $this->resourceId = $this->authorizationCatalog->syncProtectedResource(new ProtectedResourceDefinition(
            'example.work-item',
            'example',
            'Work item',
            'business_target_owned',
            'example.work-item.standard',
            '1.0.0',
            str_repeat('a', 64),
        ));
        $this->targetTypeId = $this->authorizationCatalog->syncTargetType(new TargetTypeDefinition(
            'example.project',
            'example',
            'Project',
            'example.project.resolver',
            'example.project.catalog',
            'decimal',
            '1.0.0',
            str_repeat('b', 64),
        ));
        $this->conditionDefinitions();

        $this->alphaTenant = $this->tenant('alpha');
        $this->betaTenant = $this->tenant('beta');
        $this->department($this->alphaTenant, 1, null, 'root');
        $this->department($this->alphaTenant, 2, 1, 'child');
        $this->department($this->alphaTenant, 3, null, 'sibling');
        $this->department($this->betaTenant, 4, null, 'beta-root');

        $this->accountId = $this->account('Alpha member');
        $this->memberId = $this->member($this->alphaTenant, $this->accountId, 1);
        $otherAccount = $this->account('No permission');
        $this->otherMemberId = $this->member($this->alphaTenant, $otherAccount, null);
        $this->roleA = $this->role($this->alphaTenant, 'role-a');
        $this->roleB = $this->role($this->alphaTenant, 'role-b');
        $this->assignRole($this->alphaTenant, $this->memberId, $this->roleA);
        $this->assignRole($this->alphaTenant, $this->memberId, $this->roleB);
        $this->grant($this->alphaTenant, $this->roleA, $this->readPermissionId);
        $this->grant($this->alphaTenant, $this->roleA, $this->updatePermissionId);
        $this->grant($this->alphaTenant, $this->roleB, $this->readPermissionId);
        $this->insert('pa_tenant_module', [
            'tenant_id' => $this->alphaTenant,
            'module_key' => 'example',
            'status' => 'enabled',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->context = $this->context($this->memberId, $this->accountId);
    }

    private function seedBusinessFixtures(): void
    {
        foreach ([
            [101, $this->alphaTenant, 'Project A'],
            [102, $this->alphaTenant, 'Project B'],
            [103, $this->alphaTenant, 'Project C'],
            [201, $this->betaTenant, 'Beta Project'],
        ] as [$id, $tenantId, $name]) {
            $statement = $this->database->prepare('INSERT INTO test_project (id, tenant_id, name) VALUES (?, ?, ?)');
            $statement->execute([$id, $tenantId, $name]);
        }
        foreach ([
            [1, $this->alphaTenant, $this->memberId, 1, 101, 'Alpha A'],
            [2, $this->alphaTenant, $this->otherMemberId, 2, 102, 'Alpha B'],
            [3, $this->alphaTenant, $this->otherMemberId, 3, 103, 'Alpha C'],
            [4, $this->betaTenant, 999, 4, 201, 'Beta'],
        ] as $row) {
            $statement = $this->database->prepare(<<<'SQL'
INSERT INTO test_work_item (id, tenant_id, created_by_member_id, department_id, project_id, name)
VALUES (?, ?, ?, ?, ?, ?)
SQL);
            $statement->execute($row);
        }
    }

    private function engine(): DataPermissionEngine
    {
        $provider = new StandardResourcePolicyProvider(
            new ProviderColumnMap(
                new ColumnReference('work_item.tenant_id'),
                new ColumnReference('work_item.created_by_member_id'),
                new ColumnReference('work_item.department_id'),
                ['example.project' => new ColumnReference('work_item.project_id')],
            ),
            new PdoDepartmentHierarchyProvider($this->database),
            new PdoTargetSetMembershipProvider($this->database),
            new ConditionProviderRegistry(),
        );
        $providers = new ResourceProviderRegistry();
        $providers->registerQuery('example.work-item.standard', $provider);
        $providers->registerTarget('example.work-item.standard', $provider);
        $providers->registerCreate('example.work-item.standard', $provider);
        $resolvers = new TargetResolverRegistry();
        $resolvers->register('example.project.resolver', new ProjectTargetResolver($this->database));

        return new DataPermissionEngine(
            new PdoResourceOperationCatalog($this->database),
            new PdoPolicyRepository($this->database),
            new PolicyCache(),
            new TenantAuthorizationEvaluator(
                new PdoTenantAuthorizationRepository($this->database),
                new RevisionPermissionCache(),
            ),
            $providers,
            $resolvers,
            new TargetCatalogProviderRegistry(),
            new SharedMasterScopeProviderRegistry(),
        );
    }

    private function operation(
        string $operation,
        string $accessMode,
        string $cardinality,
        int $permissionId,
    ): int {
        $operationId = $this->authorizationCatalog->syncResourceOperation(new ResourceOperationDefinition(
            'example.work-item',
            $operation,
            $accessMode,
            $cardinality,
            'all',
            'deny_and_write',
            hash('sha256', $operation),
        ));
        $this->authorizationCatalog->bindOperationPermission($operationId, $permissionId);
        if ($cardinality !== 'none') {
            $this->authorizationCatalog->bindOperationTargetType(
                $operationId,
                $this->targetTypeId,
                'primary',
                'explicit',
                $permissionId,
            );
        }
        foreach ($this->conditionIds as $key => $definitionId) {
            $this->insert('pa_resource_operation_condition', [
                'resource_operation_id' => $operationId,
                'condition_definition_id' => $definitionId,
                'selector_resource_key' => $key === 'core.specified_objects' ? 'example.project' : null,
            ]);
        }

        return $operationId;
    }

    /**
     * @param list<list<array{string, int|null}>> $groups
     */
    private function policy(int $roleId, int $operationId, array $groups): int
    {
        $policyId = $this->insert('pa_data_permission_policy', [
            'tenant_id' => $this->alphaTenant,
            'role_id' => $roleId,
            'protected_resource_id' => $this->resourceId,
            'resource_operation_id' => $operationId,
            'status' => 'active',
            'created_by_member_id' => $this->memberId,
            'updated_by_member_id' => $this->memberId,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        foreach ($groups as $index => $conditions) {
            $groupId = $this->insert('pa_data_permission_group', [
                'tenant_id' => $this->alphaTenant,
                'data_permission_policy_id' => $policyId,
                'name' => 'Group ' . ($index + 1),
                'sort_order' => $index,
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
            foreach ($conditions as [$conditionKey, $targetSetId]) {
                $this->insert('pa_data_permission_condition', [
                    'tenant_id' => $this->alphaTenant,
                    'data_permission_group_id' => $groupId,
                    'condition_definition_id' => $this->conditionIds[$conditionKey],
                    'target_set_id' => $targetSetId,
                    'created_at' => self::NOW,
                    'updated_at' => self::NOW,
                ]);
            }
        }

        return $policyId;
    }

    /** @param list<string> $targetIds */
    private function targetSet(string $resourceKey, array $targetIds): int
    {
        $targetSetId = $this->insert('pa_data_permission_target_set', [
            'tenant_id' => $this->alphaTenant,
            'name' => $resourceKey . '-' . count($targetIds) . '-' . bin2hex(random_bytes(4)),
            'target_mode' => $resourceKey === 'core.department' ? 'department' : 'resource',
            'target_resource_key' => $resourceKey,
            'created_by_member_id' => $this->memberId,
            'updated_by_member_id' => $this->memberId,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $statement = $this->database->prepare(<<<'SQL'
INSERT INTO pa_data_permission_target (tenant_id, target_set_id, target_id, added_by_member_id, added_at)
VALUES (:tenant_id, :target_set_id, :target_id, :member_id, :added_at)
SQL);
        foreach ($targetIds as $targetId) {
            $statement->execute([
                'tenant_id' => $this->alphaTenant,
                'target_set_id' => $targetSetId,
                'target_id' => $targetId,
                'member_id' => $this->memberId,
                'added_at' => self::NOW,
            ]);
        }

        return $targetSetId;
    }

    /** @return list<int> */
    private function authorizedIds(string $operation): array
    {
        $compiled = (new PdoQueryConstraintCompiler())->compile($this->engine->queryConstraint(
            $this->context,
            'example.work-item',
            $operation,
        ));
        $statement = $this->database->prepare(
            'SELECT id FROM test_work_item work_item WHERE ' . $compiled->sql . ' ORDER BY id',
        );
        $statement->execute($compiled->parameters);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function conditionDefinitions(): void
    {
        foreach ([
            'core.tenant_all',
            'core.self',
            'core.own_department',
            'core.department_tree',
            'core.specified_departments',
            'core.specified_objects',
        ] as $key) {
            $this->conditionIds[$key] = $this->authorizationCatalog->dataConditionId($key);
        }
    }

    private function tenant(string $code): int
    {
        return $this->insert('pa_tenant', [
            'code' => $code,
            'name' => $code,
            'display_name' => $code,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function account(string $name): int
    {
        return $this->insert('pa_account', [
            'display_name' => $name,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function department(int $tenantId, int $id, ?int $parentId, string $code): void
    {
        $statement = $this->database->prepare(<<<'SQL'
INSERT INTO pa_department (id, tenant_id, parent_id, code, name, created_at, updated_at)
VALUES (:id, :tenant_id, :parent_id, :code, :name, :created_at, :updated_at)
SQL);
        $statement->execute([
            'id' => $id,
            'tenant_id' => $tenantId,
            'parent_id' => $parentId,
            'code' => $code,
            'name' => $code,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function member(int $tenantId, int $accountId, ?int $departmentId): int
    {
        return $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'primary_department_id' => $departmentId,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function role(int $tenantId, string $key): int
    {
        return $this->insert('pa_role', [
            'tenant_id' => $tenantId,
            'key' => $key,
            'name' => $key,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function assignRole(int $tenantId, int $memberId, int $roleId): void
    {
        $this->insert('pa_member_role', [
            'tenant_id' => $tenantId,
            'tenant_member_id' => $memberId,
            'role_id' => $roleId,
            'assigned_at' => self::NOW,
        ]);
    }

    private function grant(int $tenantId, int $roleId, int $permissionId): void
    {
        $this->insert('pa_role_permission', [
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'granted_at' => self::NOW,
        ]);
    }

    private function context(int $memberId, int $accountId): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            'session-' . $memberId,
            $this->alphaTenant,
            $accountId,
            $memberId,
            'web',
            new DateTimeImmutable(self::NOW),
            1,
        ), 'request-' . $memberId);
    }

    /** @param list<string> $ids */
    private function targets(string $resourceKey, array $ids): TypedResourceTargetCollection
    {
        return new TypedResourceTargetCollection([
            new TypedResourceTargetSet($resourceKey, $ids),
        ]);
    }

    private function expectDataError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected data authorization error {$code}.");
        } catch (DataAuthorizationException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}

final readonly class ProjectTargetResolver implements ResourceTargetResolver
{
    public function __construct(private PDO $pdo) {}

    public function resolveAndValidate(
        TenantContext $context,
        TypedResourceTargetSet $targets,
    ): ResolvedResourceTargets {
        $placeholders = implode(', ', array_fill(0, count($targets->targetIds), '?'));
        $statement = $this->pdo->prepare(
            "SELECT id FROM test_project WHERE tenant_id = ? AND id IN ({$placeholders})",
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
