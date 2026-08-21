<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Integration\Schema;

use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__, 4) . '/kernel/tests/Integration/Schema/DatabaseTestCase.php';
require_once __DIR__ . '/DataPermissionMigrationRunner.php';

final class DataPermissionSchemaTest extends DatabaseTestCase
{
    private const NOW = '2026-07-16 04:00:00.000';

    private DataPermissionMigrationRunner $dataRunner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
        $this->dataRunner = new DataPermissionMigrationRunner(
            self::DATABASE,
            '127.0.0.1',
            (int) (getenv('MYSQL_PORT') ?: 3306),
            'root',
            getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev',
        );
        $this->dataRunner->migrate();
    }

    public function testDataPermissionMigrationsInstallRepeatAndRollback(): void
    {
        $this->dataRunner->migrate();
        self::assertSame(5, (int) $this->query(
            'SELECT COUNT(*) FROM pa_data_permission_migration',
        )->fetchColumn());

        $this->dataRunner->rollbackAll();
        self::assertFalse($this->tableExists('pa_data_permission_policy'));
        self::assertFalse($this->tableExists('pa_data_permission_target'));
        self::assertTrue($this->tableExists('pa_tenant'));
    }

    public function testGeneratedKeysAndTenantForeignKeysRejectAmbiguousPolicies(): void
    {
        [$alpha, $alphaMember, $alphaRole] = $this->tenantFixture('alpha');
        [$beta, $betaMember, $betaRole] = $this->tenantFixture('beta');
        [$resource, $operation, $conditionDefinition] = $this->registryFixture();

        $this->assertDatabaseRejects(fn() => $this->policy(
            $alpha,
            $betaRole,
            $resource,
            $operation,
            $alphaMember,
        ));
        $policy = $this->policy($alpha, $alphaRole, $resource, $operation, $alphaMember);
        $group = $this->insert('pa_data_permission_group', [
            'tenant_id' => $alpha,
            'data_permission_policy_id' => $policy,
            'name' => 'default',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $targetSet = $this->targetSet($alpha, $alphaMember, 'example.project');
        $betaTargetSet = $this->targetSet($beta, $betaMember, 'example.project');

        $this->insert('pa_data_permission_condition', [
            'tenant_id' => $alpha,
            'data_permission_group_id' => $group,
            'condition_definition_id' => $conditionDefinition,
            'target_set_id' => null,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->assertDatabaseRejects(fn() => $this->insert('pa_data_permission_condition', [
            'tenant_id' => $alpha,
            'data_permission_group_id' => $group,
            'condition_definition_id' => $conditionDefinition,
            'target_set_id' => null,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]));
        $this->assertDatabaseRejects(fn() => $this->insert('pa_data_permission_condition', [
            'tenant_id' => $alpha,
            'data_permission_group_id' => $group,
            'condition_definition_id' => $conditionDefinition,
            'target_set_id' => $betaTargetSet,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]));

        $this->insert('pa_data_permission_target', [
            'tenant_id' => $alpha,
            'target_set_id' => $targetSet,
            'target_id' => 'project-a',
            'added_by_member_id' => $alphaMember,
            'added_at' => self::NOW,
        ]);
        $this->assertDatabaseRejects(fn() => $this->insert('pa_data_permission_target', [
            'tenant_id' => $alpha,
            'target_set_id' => $targetSet,
            'target_id' => 'project-a',
            'added_by_member_id' => $alphaMember,
            'added_at' => self::NOW,
        ]));

        $this->assertDatabaseRejects(fn() => $this->insert('pa_data_permission_policy', [
            'tenant_id' => $beta,
            'role_id' => $betaRole,
            'protected_resource_id' => $resource,
            'resource_operation_id' => $operation,
            'valid_from' => '2026-08-01 00:00:00.000',
            'valid_until' => '2026-07-01 00:00:00.000',
            'created_by_member_id' => $betaMember,
            'updated_by_member_id' => $betaMember,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]));
    }

    public function testRegistryRejectsUnknownTargetTypesAndNullableDuplicates(): void
    {
        [$resource, $operation, $conditionDefinition] = $this->registryFixture();
        $this->assertDatabaseRejects(fn() => $this->insert('pa_resource_operation_target_type', [
            'resource_operation_id' => $operation,
            'target_type_id' => 999_999,
        ]));

        $this->insert('pa_resource_operation_condition', [
            'resource_operation_id' => $operation,
            'condition_definition_id' => $conditionDefinition,
            'selector_resource_key' => null,
        ]);
        $this->assertDatabaseRejects(fn() => $this->insert('pa_resource_operation_condition', [
            'resource_operation_id' => $operation,
            'condition_definition_id' => $conditionDefinition,
            'selector_resource_key' => null,
        ]));

        self::assertSame(1, $resource);
    }

    /** @return array{int, int, int} */
    private function tenantFixture(string $code): array
    {
        $account = $this->insert('pa_account', [
            'display_name' => ucfirst($code),
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $tenant = $this->insert('pa_tenant', [
            'code' => $code,
            'name' => ucfirst($code),
            'display_name' => ucfirst($code),
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $member = $this->insert('pa_tenant_member', [
            'tenant_id' => $tenant,
            'account_id' => $account,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $role = $this->insert('pa_role', [
            'tenant_id' => $tenant,
            'key' => 'manager',
            'name' => 'Manager',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return [$tenant, $member, $role];
    }

    /** @return array{int, int, int} */
    private function registryFixture(): array
    {
        $resource = $this->insert('pa_protected_resource', [
            'key' => 'example.work-item',
            'module_key' => 'example',
            'name' => 'Work Item',
            'ownership' => 'business_target_owned',
            'provider_key' => 'example.work-item.provider',
            'manifest_version' => '1.0.0',
            'manifest_digest' => str_repeat('a', 64),
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $operation = $this->insert('pa_resource_operation', [
            'protected_resource_id' => $resource,
            'operation' => 'list',
            'access_mode' => 'rule_filtered',
            'target_cardinality' => 'many_readable',
            'manifest_digest' => str_repeat('b', 64),
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $condition = $this->insert('pa_data_condition_definition', [
            'key' => 'core.self',
            'module_key' => 'core',
            'category' => 'self',
            'target_mode' => 'none',
            'manifest_version' => '1.0.0',
            'manifest_digest' => str_repeat('c', 64),
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return [$resource, $operation, $condition];
    }

    private function policy(
        int $tenantId,
        int $roleId,
        int $resourceId,
        int $operationId,
        int $memberId,
    ): int {
        return $this->insert('pa_data_permission_policy', [
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'protected_resource_id' => $resourceId,
            'resource_operation_id' => $operationId,
            'created_by_member_id' => $memberId,
            'updated_by_member_id' => $memberId,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function targetSet(int $tenantId, int $memberId, string $resourceKey): int
    {
        return $this->insert('pa_data_permission_target_set', [
            'tenant_id' => $tenantId,
            'name' => 'projects-' . $tenantId,
            'target_mode' => 'resource',
            'target_resource_key' => $resourceKey,
            'created_by_member_id' => $memberId,
            'updated_by_member_id' => $memberId,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->database->prepare(<<<'SQL'
SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table
SQL);
        self::assertNotFalse($statement);
        $statement->execute(['schema' => self::DATABASE, 'table' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }
}
