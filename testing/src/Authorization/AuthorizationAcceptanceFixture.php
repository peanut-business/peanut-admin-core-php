<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Authorization;

use DateTimeImmutable;
use PDO;
use PDOStatement;
use PeanutAdmin\DataPermission\Catalog\PdoResourceOperationCatalog;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Engine\DataPermissionEngine;
use PeanutAdmin\DataPermission\Policy\PdoPolicyRepository;
use PeanutAdmin\DataPermission\Policy\PolicyCache;
use PeanutAdmin\DataPermission\Provider\ConditionProviderRegistry;
use PeanutAdmin\DataPermission\Provider\PdoDepartmentHierarchyProvider;
use PeanutAdmin\DataPermission\Provider\PdoTargetSetMembershipProvider;
use PeanutAdmin\DataPermission\Provider\ProviderColumnMap;
use PeanutAdmin\DataPermission\Provider\ResourceProviderRegistry;
use PeanutAdmin\DataPermission\Provider\SharedMasterScopeProviderRegistry;
use PeanutAdmin\DataPermission\Provider\StandardResourcePolicyProvider;
use PeanutAdmin\DataPermission\Target\TargetCatalogProviderRegistry;
use PeanutAdmin\DataPermission\Target\TargetResolverRegistry;
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
use RuntimeException;

final class AuthorizationAcceptanceFixture
{
    private const NOW = '2026-07-16 12:00:00.000';

    private PdoAuthorizationCatalogRepository $catalog;
    private int $alphaTenant;
    private int $betaTenant;
    private int $accountId;
    private int $alphaMember;
    private int $betaMember;
    private int $readerRole;
    private int $writerRole;
    private int $resourceId;
    private int $referenceResourceId;
    private int $unregisteredReferenceResourceId;
    private int $projectTargetType;

    /** @var array<string, int> */
    private array $permissions = [];

    /** @var array<string, int> */
    private array $operations = [];

    /** @var array<string, int> */
    private array $conditionIds = [];

    /** @var array<string, int> */
    private array $policyIds = [];

    /** @var array<string, int> */
    private array $recordIds = [];

    private function __construct(private readonly PDO $pdo) {}

    public static function install(PDO $pdo): AuthorizationAcceptanceEnvironment
    {
        $fixture = new self($pdo);

        return $fixture->build();
    }

    private function build(): AuthorizationAcceptanceEnvironment
    {
        $this->createTables();
        $this->seedCatalog();
        $this->seedPrincipals();
        $this->seedTargetsAndRecords();
        $this->seedPolicies();
        $engine = $this->engine();
        $alphaContext = $this->context($this->alphaTenant, $this->alphaMember, $this->accountId, 'alpha');
        $betaContext = $this->context($this->betaTenant, $this->betaMember, $this->accountId, 'beta');
        $trace = new AuthorizationSqlTrace();

        return new AuthorizationAcceptanceEnvironment(
            $engine,
            $alphaContext,
            $betaContext,
            new ResourceProviderContractHarness($this->pdo, $engine, $alphaContext, $trace),
            $trace,
            $this->accountId,
            $this->alphaTenant,
            $this->betaTenant,
            $this->alphaMember,
            $this->betaMember,
            $this->recordIds,
            $this->policyIds,
        );
    }

    private function createTables(): void
    {
        foreach ([
            <<<'SQL'
CREATE TABLE fixture_project (
  tenant_id BIGINT UNSIGNED NOT NULL,
  id VARCHAR(32) NOT NULL,
  name VARCHAR(120) NOT NULL,
  PRIMARY KEY (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            <<<'SQL'
CREATE TABLE fixture_queue (
  tenant_id BIGINT UNSIGNED NOT NULL,
  id VARCHAR(32) NOT NULL,
  name VARCHAR(120) NOT NULL,
  PRIMARY KEY (tenant_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            <<<'SQL'
CREATE TABLE fixture_record (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  project_id VARCHAR(32) NOT NULL,
  created_by_member_id BIGINT UNSIGNED NOT NULL,
  code VARCHAR(64) NULL,
  name VARCHAR(120) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_fixture_record_code (tenant_id, code),
  KEY idx_fixture_record_tenant_project (tenant_id, project_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            <<<'SQL'
CREATE TABLE fixture_target_visibility (
  tenant_id BIGINT UNSIGNED NOT NULL,
  member_id BIGINT UNSIGNED NOT NULL,
  target_id VARCHAR(32) NOT NULL,
  PRIMARY KEY (tenant_id, member_id, target_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            <<<'SQL'
CREATE TABLE fixture_reference (
  id VARCHAR(32) NOT NULL,
  owner_tenant_id BIGINT UNSIGNED NULL,
  owner_project_id VARCHAR(32) NULL,
  visibility VARCHAR(16) NOT NULL,
  name VARCHAR(120) NOT NULL,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
            <<<'SQL'
CREATE TABLE fixture_reference_visibility (
  reference_id VARCHAR(32) NOT NULL,
  tenant_id BIGINT UNSIGNED NOT NULL,
  project_id VARCHAR(32) NOT NULL,
  PRIMARY KEY (reference_id, tenant_id, project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
SQL,
        ] as $sql) {
            $this->pdo->exec($sql);
        }
    }

    private function seedCatalog(): void
    {
        $this->catalog = new PdoAuthorizationCatalogRepository($this->pdo);
        (new CorePermissionCatalogSynchronizer($this->catalog))->synchronize();
        $this->insert('pa_module_installation', [
            'module_key' => 'fixture',
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => hash('sha256', 'fixture'),
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        foreach ([
            'menu', 'read', 'create', 'update', 'delete', 'batch', 'import',
            'export', 'job', 'aggregate', 'reference.read', 'reference.use', 'project.select',
        ] as $action) {
            $key = 'fixture.record.' . $action;
            if (str_starts_with($action, 'reference.')) {
                $key = 'fixture.' . $action;
            } elseif ($action === 'project.select') {
                $key = 'fixture.project.select';
            }
            $this->permissions[$key] = $this->catalog->syncPermission(new PermissionDefinition(
                $key,
                'fixture',
                $action === 'menu' ? 'menu' : 'api',
                $key,
                in_array($action, ['delete', 'batch', 'import'], true) ? 'sensitive' : 'normal',
                '1.0.0',
            ));
        }
        $this->resourceId = $this->catalog->syncProtectedResource(new ProtectedResourceDefinition(
            'fixture.record',
            'fixture',
            'Fixture record',
            'business_target_owned',
            'fixture.record.standard',
            '1.0.0',
            str_repeat('a', 64),
        ));
        $this->referenceResourceId = $this->catalog->syncProtectedResource(new ProtectedResourceDefinition(
            'fixture.reference',
            'fixture',
            'Fixture reference',
            'shared_master',
            'fixture.reference.standard',
            '1.0.0',
            str_repeat('b', 64),
        ));
        $this->unregisteredReferenceResourceId = $this->catalog->syncProtectedResource(new ProtectedResourceDefinition(
            'fixture.reference-unregistered',
            'fixture',
            'Unregistered reference',
            'shared_master',
            'fixture.reference.standard',
            '1.0.0',
            str_repeat('c', 64),
        ));
        $this->projectTargetType = $this->catalog->syncTargetType(new TargetTypeDefinition(
            'fixture.project',
            'fixture',
            'Project',
            'fixture.project.resolver',
            'fixture.project.catalog',
            'string',
            '1.0.0',
            str_repeat('d', 64),
        ));
        $this->catalog->syncTargetType(new TargetTypeDefinition(
            'fixture.queue',
            'fixture',
            'Queue',
            'fixture.queue.resolver',
            'fixture.queue.catalog',
            'string',
            '1.0.0',
            str_repeat('e', 64),
        ));
        $this->conditionIds['core.tenant_all'] = $this->catalog->dataConditionId('core.tenant_all');
        $this->conditionIds['core.specified_objects'] = $this->catalog->dataConditionId('core.specified_objects');

        foreach ([
            'list' => ['rule_filtered', 'many_readable', 'fixture.record.read'],
            'detail' => ['explicit_targets', 'one_required', 'fixture.record.read'],
            'create' => ['explicit_targets', 'one_required', 'fixture.record.create'],
            'update' => ['explicit_targets', 'one_required', 'fixture.record.update'],
            'delete' => ['explicit_targets', 'one_required', 'fixture.record.delete'],
            'batch' => ['explicit_targets', 'one_required', 'fixture.record.batch'],
            'import' => ['explicit_targets', 'one_required', 'fixture.record.import'],
            'export' => ['rule_filtered', 'many_readable', 'fixture.record.export'],
            'job' => ['rule_filtered', 'many_readable', 'fixture.record.job'],
            'aggregate' => ['rule_filtered', 'aggregate_read', 'fixture.record.aggregate'],
            'bulk-write' => ['explicit_targets', 'bulk_write', 'fixture.record.batch'],
            'policy-publish' => ['explicit_targets', 'policy_publish', 'fixture.record.update'],
        ] as $operation => [$mode, $cardinality, $permission]) {
            $this->operations[$operation] = $this->operation(
                $this->resourceId,
                'fixture.record',
                $operation,
                $mode,
                $cardinality,
                $this->permissions[$permission],
                true,
            );
        }
        $this->operations['reference-list'] = $this->operation(
            $this->referenceResourceId,
            'fixture.reference',
            'list',
            'rule_filtered',
            'many_readable',
            $this->permissions['fixture.reference.read'],
            true,
        );
        $this->operations['reference-use'] = $this->operation(
            $this->referenceResourceId,
            'fixture.reference',
            'use',
            'explicit_targets',
            'one_required',
            $this->permissions['fixture.reference.use'],
            true,
        );
        $this->operations['unregistered-reference-list'] = $this->operation(
            $this->unregisteredReferenceResourceId,
            'fixture.reference-unregistered',
            'list',
            'rule_filtered',
            'many_readable',
            $this->permissions['fixture.reference.read'],
            true,
        );
    }

    private function seedPrincipals(): void
    {
        $this->alphaTenant = $this->tenant('alpha');
        $this->betaTenant = $this->tenant('beta');
        $this->accountId = $this->insert('pa_account', [
            'display_name' => 'Multi-tenant account',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->alphaMember = $this->member($this->alphaTenant, $this->accountId);
        $this->betaMember = $this->member($this->betaTenant, $this->accountId);
        $this->readerRole = $this->role($this->alphaTenant, 'fixture-reader');
        $this->writerRole = $this->role($this->alphaTenant, 'fixture-writer');
        $betaMenuRole = $this->role($this->betaTenant, 'fixture-menu-only');
        $this->assignRole($this->alphaTenant, $this->alphaMember, $this->readerRole);
        $this->assignRole($this->alphaTenant, $this->alphaMember, $this->writerRole);
        $this->assignRole($this->betaTenant, $this->betaMember, $betaMenuRole);

        foreach ([
            'fixture.record.read', 'fixture.record.export', 'fixture.record.job',
            'fixture.record.aggregate', 'fixture.reference.read', 'fixture.reference.use',
            'core.role.data-policy.manage',
        ] as $permission) {
            $permissionId = str_starts_with($permission, 'core.')
                ? $this->permissionId($permission)
                : $this->permissions[$permission];
            $this->grant($this->alphaTenant, $this->readerRole, $permissionId);
        }
        foreach ([
            'fixture.record.create', 'fixture.record.update', 'fixture.record.delete',
            'fixture.record.batch', 'fixture.record.import',
        ] as $permission) {
            $this->grant($this->alphaTenant, $this->writerRole, $this->permissions[$permission]);
        }
        $this->grant($this->betaTenant, $betaMenuRole, $this->permissions['fixture.record.menu']);
        foreach ([$this->alphaTenant, $this->betaTenant] as $tenantId) {
            $this->insert('pa_tenant_module', [
                'tenant_id' => $tenantId,
                'module_key' => 'fixture',
                'status' => 'enabled',
                'created_at' => self::NOW,
                'updated_at' => self::NOW,
            ]);
        }
    }

    private function seedTargetsAndRecords(): void
    {
        foreach ([
            [$this->alphaTenant, 'A', 'Project A'],
            [$this->alphaTenant, 'B', 'Project B'],
            [$this->alphaTenant, 'C', 'Project C'],
            [$this->betaTenant, 'A', 'Beta Project A'],
        ] as $row) {
            $this->execute('INSERT INTO fixture_project (tenant_id, id, name) VALUES (?, ?, ?)', $row);
        }
        $this->execute(
            'INSERT INTO fixture_queue (tenant_id, id, name) VALUES (?, ?, ?)',
            [$this->alphaTenant, 'A', 'Queue A'],
        );
        foreach (['A', 'B'] as $projectId) {
            $this->execute(
                'INSERT INTO fixture_target_visibility (tenant_id, member_id, target_id) VALUES (?, ?, ?)',
                [$this->alphaTenant, $this->alphaMember, $projectId],
            );
        }
        foreach ([
            'alpha_a_1' => [$this->alphaTenant, 'A', $this->alphaMember, 'Alpha A 1'],
            'alpha_a_2' => [$this->alphaTenant, 'A', $this->alphaMember, 'Alpha A 2'],
            'alpha_b' => [$this->alphaTenant, 'B', $this->alphaMember, 'Alpha B'],
            'alpha_c' => [$this->alphaTenant, 'C', $this->alphaMember, 'Alpha C'],
            'beta_a' => [$this->betaTenant, 'A', $this->betaMember, 'Beta A'],
        ] as $key => [$tenantId, $projectId, $memberId, $name]) {
            $this->execute(<<<'SQL'
INSERT INTO fixture_record (tenant_id, project_id, created_by_member_id, name)
VALUES (?, ?, ?, ?)
SQL, [$tenantId, $projectId, $memberId, $name]);
            $this->recordIds[$key] = (int) $this->pdo->lastInsertId();
        }
        foreach ([
            ['PUBLIC', null, null, 'public', 'Public reference'],
            ['PRIVATE_A', $this->alphaTenant, 'A', 'private', 'Private A'],
            ['PRIVATE_C', $this->alphaTenant, 'C', 'private', 'Private C'],
        ] as $row) {
            $this->execute(<<<'SQL'
INSERT INTO fixture_reference (id, owner_tenant_id, owner_project_id, visibility, name)
VALUES (?, ?, ?, ?, ?)
SQL, $row);
        }
        $this->execute(
            'INSERT INTO fixture_reference_visibility (reference_id, tenant_id, project_id) VALUES (?, ?, ?)',
            ['PRIVATE_A', $this->alphaTenant, 'A'],
        );
        $this->execute(
            'INSERT INTO fixture_reference_visibility (reference_id, tenant_id, project_id) VALUES (?, ?, ?)',
            ['PRIVATE_C', $this->alphaTenant, 'C'],
        );
    }

    private function seedPolicies(): void
    {
        $readTargets = $this->targetSet(['A', 'B']);
        $writeTargets = $this->targetSet(['A']);
        foreach (['list', 'detail', 'export', 'job', 'aggregate'] as $operation) {
            $this->policyIds[$operation] = $this->policy(
                $this->readerRole,
                $this->resourceId,
                $this->operations[$operation],
                'core.specified_objects',
                $readTargets,
            );
        }
        foreach (['create', 'update', 'delete', 'batch', 'import', 'bulk-write', 'policy-publish'] as $operation) {
            $this->policyIds[$operation] = $this->policy(
                $this->writerRole,
                $this->resourceId,
                $this->operations[$operation],
                'core.specified_objects',
                $writeTargets,
            );
        }
        foreach (['reference-list', 'reference-use', 'unregistered-reference-list'] as $operation) {
            $resourceId = $operation === 'unregistered-reference-list'
                ? $this->unregisteredReferenceResourceId
                : $this->referenceResourceId;
            $this->policyIds[$operation] = $this->policy(
                $this->readerRole,
                $resourceId,
                $this->operations[$operation],
                'core.tenant_all',
                null,
            );
        }
    }

    private function engine(): DataPermissionEngine
    {
        $providers = new ResourceProviderRegistry();
        $recordProvider = new StandardResourcePolicyProvider(
            new ProviderColumnMap(
                new ColumnReference('record.tenant_id'),
                new ColumnReference('record.created_by_member_id'),
                null,
                ['fixture.project' => new ColumnReference('record.project_id')],
            ),
            new PdoDepartmentHierarchyProvider($this->pdo),
            new PdoTargetSetMembershipProvider($this->pdo),
            new ConditionProviderRegistry(),
        );
        $referenceProvider = new StandardResourcePolicyProvider(
            new ProviderColumnMap(
                new ColumnReference('reference.owner_tenant_id'),
                null,
                null,
                ['fixture.project' => new ColumnReference('reference.owner_project_id')],
            ),
            new PdoDepartmentHierarchyProvider($this->pdo),
            new PdoTargetSetMembershipProvider($this->pdo),
            new ConditionProviderRegistry(),
        );
        foreach (['fixture.record.standard' => $recordProvider, 'fixture.reference.standard' => $referenceProvider] as $key => $provider) {
            $providers->registerQuery($key, $provider);
            $providers->registerTarget($key, $provider);
            $providers->registerCreate($key, $provider);
        }
        $resolvers = new TargetResolverRegistry();
        $resolvers->register('fixture.project.resolver', new FixtureTargetResolver($this->pdo, 'fixture.project'));
        $resolvers->register('fixture.queue.resolver', new FixtureTargetResolver($this->pdo, 'fixture.queue'));
        $catalogProviders = new TargetCatalogProviderRegistry();
        $catalogProviders->register('fixture.project.catalog', new FixtureTargetCatalogProvider($this->pdo));
        $shared = new SharedMasterScopeProviderRegistry();
        $shared->register('fixture.reference', new FixtureSharedMasterScopeProvider($this->pdo));

        return new DataPermissionEngine(
            new PdoResourceOperationCatalog($this->pdo),
            new PdoPolicyRepository($this->pdo),
            new PolicyCache(),
            new TenantAuthorizationEvaluator(
                new PdoTenantAuthorizationRepository($this->pdo),
                new RevisionPermissionCache(),
            ),
            $providers,
            $resolvers,
            $catalogProviders,
            $shared,
        );
    }

    private function operation(
        int $resourceId,
        string $resourceKey,
        string $operation,
        string $accessMode,
        string $cardinality,
        int $permissionId,
        bool $withProjectTarget,
    ): int {
        $operationId = $this->catalog->syncResourceOperation(new ResourceOperationDefinition(
            $resourceKey,
            $operation,
            $accessMode,
            $cardinality,
            'all',
            'deny_and_write',
            hash('sha256', $resourceKey . ':' . $operation),
        ));
        $this->catalog->bindOperationPermission($operationId, $permissionId);
        if ($withProjectTarget) {
            $this->catalog->bindOperationTargetType(
                $operationId,
                $this->projectTargetType,
                'primary',
                'explicit',
                $this->permissions['fixture.project.select'],
            );
        }
        foreach ($this->conditionIds as $key => $conditionId) {
            $this->insert('pa_resource_operation_condition', [
                'resource_operation_id' => $operationId,
                'condition_definition_id' => $conditionId,
                'selector_resource_key' => $key === 'core.specified_objects' ? 'fixture.project' : null,
            ]);
        }

        return $operationId;
    }

    /** @param list<string> $targetIds */
    private function targetSet(array $targetIds): int
    {
        $targetSetId = $this->insert('pa_data_permission_target_set', [
            'tenant_id' => $this->alphaTenant,
            'name' => 'Targets ' . implode('-', $targetIds),
            'target_mode' => 'resource',
            'target_resource_key' => 'fixture.project',
            'created_by_member_id' => $this->alphaMember,
            'updated_by_member_id' => $this->alphaMember,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        foreach ($targetIds as $targetId) {
            $this->insert('pa_data_permission_target', [
                'tenant_id' => $this->alphaTenant,
                'target_set_id' => $targetSetId,
                'target_id' => $targetId,
                'added_by_member_id' => $this->alphaMember,
                'added_at' => self::NOW,
            ]);
        }

        return $targetSetId;
    }

    private function policy(
        int $roleId,
        int $resourceId,
        int $operationId,
        string $conditionKey,
        ?int $targetSetId,
    ): int {
        $policyId = $this->insert('pa_data_permission_policy', [
            'tenant_id' => $this->alphaTenant,
            'role_id' => $roleId,
            'protected_resource_id' => $resourceId,
            'resource_operation_id' => $operationId,
            'created_by_member_id' => $this->alphaMember,
            'updated_by_member_id' => $this->alphaMember,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $groupId = $this->insert('pa_data_permission_group', [
            'tenant_id' => $this->alphaTenant,
            'data_permission_policy_id' => $policyId,
            'name' => 'Allowed targets',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_data_permission_condition', [
            'tenant_id' => $this->alphaTenant,
            'data_permission_group_id' => $groupId,
            'condition_definition_id' => $this->conditionIds[$conditionKey],
            'target_set_id' => $targetSetId,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return $policyId;
    }

    private function tenant(string $code): int
    {
        return $this->insert('pa_tenant', [
            'code' => $code,
            'name' => ucfirst($code),
            'display_name' => ucfirst($code),
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function member(int $tenantId, int $accountId): int
    {
        return $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
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

    private function permissionId(string $key): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM pa_permission WHERE `key` = :permission_key');
        $statement->execute(['permission_key' => $key]);

        return (int) $statement->fetchColumn();
    }

    private function context(int $tenantId, int $memberId, int $accountId, string $suffix): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $memberId,
            'session-' . $suffix,
            $tenantId,
            $accountId,
            $memberId,
            'web',
            new DateTimeImmutable(self::NOW),
            1,
        ), 'request-' . $suffix);
    }

    /** @param array<string, int|string|null> $values */
    private function insert(string $table, array $values): int
    {
        $columns = array_keys($values);
        $statement = $this->pdo->prepare(sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(static fn(string $column): string => "`{$column}`", $columns)),
            implode(', ', array_map(static fn(string $column): string => ":{$column}", $columns)),
        ));
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('Could not prepare fixture insert.');
        }
        $statement->execute($values);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<int|string|null> $parameters */
    private function execute(string $sql, array $parameters): void
    {
        $statement = $this->pdo->prepare($sql);
        if (!$statement instanceof PDOStatement) {
            throw new RuntimeException('Could not prepare fixture statement.');
        }
        $statement->execute($parameters);
    }
}
