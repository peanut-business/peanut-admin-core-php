<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Tests\Integration\Schema;

use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;
use PeanutAdmin\Settings\Database\Schema;
use PeanutAdmin\Settings\Tests\Integration\Support\SettingsDatabaseTestCase;

require_once dirname(__DIR__) . '/Support/SettingsDatabaseTestCase.php';

final class SettingsMigrationTest extends SettingsDatabaseTestCase
{
    public function testCreatesExactlyFourTablesIdempotentlyAndRollsThemBack(): void
    {
        $this->runner->migrate();
        self::assertSame([
            'pa_setting_definition',
            'pa_setting_deployment_value',
            'pa_setting_tenant_value',
            'pa_setting_target_value',
        ], Schema::tableNames());
        self::assertSame(4, (int) $this->scalar(<<<'SQL'
SELECT COUNT(*) FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name LIKE 'pa_setting_%'
SQL));

        $this->runner->rollbackAll();
        self::assertSame(0, (int) $this->scalar(<<<'SQL'
SELECT COUNT(*) FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name LIKE 'pa_setting_%'
SQL));
        self::assertSame(1, (int) $this->scalar(<<<'SQL'
SELECT COUNT(*) FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = 'pa_tenant'
SQL));
    }

    public function testDefinitionTableHasTheExactContractColumns(): void
    {
        self::assertSame([
            'id', 'module_key', 'setting_key', 'name', 'description', 'schema_json',
            'required_flag', 'secret_flag', 'deployment_scope_flag', 'tenant_scope_flag',
            'target_scope_flag', 'target_resource_key', 'target_operation', 'default_json',
            'definition_digest', 'status', 'revision', 'created_at', 'updated_at',
        ], $this->columns('pa_setting_definition'));
    }

    public function testValueTablesHaveTheExactContractColumns(): void
    {
        self::assertSame([
            'id', 'definition_id', 'value_state', 'value_json', 'ciphertext', 'nonce',
            'key_id', 'revision', 'effective_at', 'expires_at', 'updated_by_operator_id',
            'created_at', 'updated_at',
        ], $this->columns('pa_setting_deployment_value'));
        self::assertSame([
            'id', 'tenant_id', 'definition_id', 'value_state', 'value_json', 'ciphertext',
            'nonce', 'key_id', 'revision', 'effective_at', 'expires_at',
            'updated_by_member_id', 'created_at', 'updated_at',
        ], $this->columns('pa_setting_tenant_value'));
        self::assertSame([
            'id', 'tenant_id', 'definition_id', 'target_resource_key', 'target_id',
            'value_state', 'value_json', 'ciphertext', 'nonce', 'key_id', 'revision',
            'effective_at', 'expires_at', 'updated_by_member_id', 'created_at', 'updated_at',
        ], $this->columns('pa_setting_target_value'));
    }

    public function testInstanceScopedValueTablesOmitTenantColumnsIndexesAndForeignKeys(): void
    {
        $this->runner->rollbackAll();
        $this->runner = new SettingsMigrationRunner(
            $this->database,
            TenantPersistenceMode::InstanceScoped,
        );
        $this->runner->migrate();

        foreach (['pa_setting_tenant_value', 'pa_setting_target_value'] as $table) {
            self::assertNotContains('tenant_id', $this->columns($table));
            self::assertSame(0, (int) $this->scalar(sprintf(<<<'SQL'
SELECT COUNT(*)
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND table_name = '%s' AND column_name = 'tenant_id'
SQL, $table)));
            self::assertSame(0, (int) $this->scalar(sprintf(<<<'SQL'
SELECT COUNT(*)
FROM information_schema.key_column_usage
WHERE table_schema = DATABASE() AND table_name = '%s' AND column_name = 'tenant_id'
SQL, $table)));
        }
        self::assertStringNotContainsString(
            '`tenant_id`',
            Schema::createSql('pa_setting_tenant_value', TenantPersistenceMode::InstanceScoped),
        );
        self::assertStringNotContainsString(
            '`tenant_id`',
            Schema::createSql('pa_setting_target_value', TenantPersistenceMode::InstanceScoped),
        );
    }

    public function testConstraintsRejectInvalidStatesIntervalsStorageAndForeignKeys(): void
    {
        $registry = $this->registry([$this->definition()], targets: [[
            'module_key' => 'example.module',
            'resource_key' => 'example.project',
            'operation' => 'updateProjectSetting',
            'target_cardinality' => 'one_required',
        ]]);
        $this->synchronize($registry);
        $definitionId = (int) $this->scalar('SELECT id FROM pa_setting_definition');
        $operatorId = $this->operator();

        $this->assertDatabaseRejects(fn() => $this->insertDeployment([
            'definition_id' => $definitionId,
            'value_state' => 'invalid',
            'value_json' => '"compact"',
            'updated_by_operator_id' => $operatorId,
        ]));
        $this->assertDatabaseRejects(fn() => $this->insertDeployment([
            'definition_id' => $definitionId,
            'value_state' => 'set',
            'value_json' => '"compact"',
            'ciphertext' => random_bytes(32),
            'nonce' => random_bytes(24),
            'key_id' => 'runtime',
            'updated_by_operator_id' => $operatorId,
        ]));
        $this->assertDatabaseRejects(fn() => $this->insertDeployment([
            'definition_id' => $definitionId,
            'value_state' => 'unset',
            'value_json' => '"compact"',
            'updated_by_operator_id' => $operatorId,
        ]));
        $this->assertDatabaseRejects(fn() => $this->insertDeployment([
            'definition_id' => $definitionId,
            'value_state' => 'set',
            'value_json' => '"compact"',
            'effective_at' => '2026-07-20 00:00:00.000',
            'expires_at' => '2026-07-19 00:00:00.000',
            'updated_by_operator_id' => $operatorId,
        ]));
        $this->assertDatabaseRejects(fn() => $this->insertDeployment([
            'definition_id' => 999999,
            'value_state' => 'set',
            'value_json' => '"compact"',
            'updated_by_operator_id' => $operatorId,
        ]));
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $statement = $this->database->prepare(<<<'SQL'
SELECT column_name FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = :table_name
ORDER BY ordinal_position
SQL);
        $statement->execute(['table_name' => $table]);

        return array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** @param array<string, mixed> $override */
    private function insertDeployment(array $override): void
    {
        $values = array_merge([
            'definition_id' => 1,
            'value_state' => 'set',
            'value_json' => null,
            'ciphertext' => null,
            'nonce' => null,
            'key_id' => null,
            'effective_at' => self::NOW,
            'expires_at' => null,
            'updated_by_operator_id' => 1,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ], $override);
        $statement = $this->database->prepare(<<<'SQL'
INSERT INTO pa_setting_deployment_value (
  definition_id, value_state, value_json, ciphertext, nonce, key_id,
  effective_at, expires_at, updated_by_operator_id, created_at, updated_at
) VALUES (
  :definition_id, :value_state, :value_json, :ciphertext, :nonce, :key_id,
  :effective_at, :expires_at, :updated_by_operator_id, :created_at, :updated_at
)
SQL);
        $statement->execute($values);
    }
}
