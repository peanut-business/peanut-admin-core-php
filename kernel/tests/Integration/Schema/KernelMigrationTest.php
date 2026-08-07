<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Schema;

require_once __DIR__ . '/DatabaseTestCase.php';

final class KernelMigrationTest extends DatabaseTestCase
{
    private const TABLES = [
        'pa_account',
        'pa_credential',
        'pa_tenant',
        'pa_platform_operator',
        'pa_permission',
        'pa_platform_role',
        'pa_platform_role_permission',
        'pa_platform_operator_role',
        'pa_department',
        'pa_tenant_member',
        'pa_role',
        'pa_role_permission',
        'pa_member_role',
        'pa_tenant_module',
        'pa_platform_audit_event',
        'pa_tenant_audit_event',
        'pa_login_challenge',
        'pa_tenant_session',
        'pa_tenant_session_token',
        'pa_platform_session',
        'pa_platform_session_token',
        'pa_auth_security_event',
        'pa_protected_resource',
        'pa_target_type',
        'pa_resource_operation',
        'pa_resource_operation_target_type',
        'pa_resource_operation_permission',
        'pa_data_condition_definition',
        'pa_resource_operation_condition',
        'pa_module_installation',
        'pa_module_migration',
        'pa_menu_definition',
        'pa_tenant_idempotency_record',
        'pa_platform_idempotency_record',
    ];

    public function testEmptyInstallUpgradeCopyAndRepeatInstall(): void
    {
        $this->runner->migrate(20260716010110);

        self::assertTrue($this->tableExists('pa_tenant_member'));
        self::assertFalse($this->tableExists('pa_role'));

        $this->runner->migrate();
        $this->runner->migrate();

        foreach (self::TABLES as $table) {
            self::assertTrue($this->tableExists($table), "Missing migrated table {$table}");
        }

        $migrationCount = $this
            ->query('SELECT COUNT(*) FROM `pa_kernel_migration`')
            ->fetchColumn();
        self::assertSame(40, (int) $migrationCount);

        $challengeClient = $this
            ->query("SHOW COLUMNS FROM `pa_login_challenge` WHERE Field = 'client_key'")
            ->fetch();
        self::assertIsArray($challengeClient);
        self::assertSame('NO', $challengeClient['Null']);

        $authEventIndexes = $this
            ->query("SHOW INDEX FROM `pa_auth_security_event` WHERE Key_name = 'idx_auth_event_ip'")
            ->fetchAll();
        self::assertCount(2, $authEventIndexes);
        self::assertSame(
            ['ip_address', 'occurred_at'],
            array_column($authEventIndexes, 'Column_name'),
        );
    }

    public function testControlledRollbackRemovesOnlyKernelSchema(): void
    {
        $this->runner->migrate();
        $this->runner->rollbackAll();

        foreach (self::TABLES as $table) {
            self::assertFalse($this->tableExists($table), "Rollback retained table {$table}");
        }

        self::assertTrue($this->tableExists('pa_kernel_migration'));
    }

    private function tableExists(string $table): bool
    {
        $statement = $this->database->prepare(<<<'SQL'
SELECT COUNT(*)
FROM information_schema.tables
WHERE table_schema = :schema AND table_name = :table
SQL);
        $statement->execute([
            'schema' => self::DATABASE,
            'table' => $table,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }
}
