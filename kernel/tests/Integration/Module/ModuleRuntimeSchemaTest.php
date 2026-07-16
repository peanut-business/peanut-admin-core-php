<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Module;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Module\ModuleGuard;
use PeanutAdmin\Kernel\Module\Persistence\PdoModuleRuntimeRepository;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class ModuleRuntimeSchemaTest extends DatabaseTestCase
{
    public function testModuleRuntimeTablesInstallAndRollback(): void
    {
        $this->runner->migrate();

        foreach (['pa_module_installation', 'pa_module_migration', 'pa_menu_definition'] as $table) {
            self::assertSame($table, $this->query("SHOW TABLES LIKE '{$table}'")->fetchColumn());
        }

        $this->runner->migrate();
        $this->runner->rollbackAll();
        self::assertFalse($this->query("SHOW TABLES LIKE 'pa_module_installation'")->fetchColumn());
    }

    public function testModuleInstallationAndMenuConstraintsRejectInvalidStates(): void
    {
        $this->runner->migrate();
        $now = '2026-07-16 12:00:00.000';
        $this->insert('pa_module_installation', [
            'module_key' => 'example.target',
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => str_repeat('a', 64),
            'status' => 'active',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assertDatabaseRejects(fn() => $this->insert('pa_module_installation', [
            'module_key' => 'example.target',
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => str_repeat('a', 64),
            'status' => 'unknown',
            'revision' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    public function testPdoRuntimeEnablesGuardsAndDisablesTenantModule(): void
    {
        $this->runner->migrate();
        $now = new DateTimeImmutable('2026-07-16T12:00:00Z');
        $timestamp = $now->format('Y-m-d H:i:s.v');
        $tenantId = $this->insert('pa_tenant', [
            'code' => 'alpha',
            'name' => 'Alpha',
            'display_name' => 'Alpha',
            'status' => 'active',
            'locale' => 'zh-CN',
            'timezone' => 'Asia/Shanghai',
            'security_revision' => 1,
            'authorization_revision' => 1,
            'revision' => 1,
            'activated_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $this->insert('pa_module_installation', [
            'module_key' => 'example.target',
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => str_repeat('a', 64),
            'status' => 'active',
            'revision' => 1,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
        $repository = new PdoModuleRuntimeRepository($this->database);

        $repository->enable($tenantId, 'example.target', ['mode' => 'fixture'], $now);
        (new ModuleGuard($repository))->assertMemberAccess($tenantId, 'example.target', true, $now);
        self::assertSame(2, (int) $this->query("SELECT authorization_revision FROM pa_tenant WHERE id = {$tenantId}")->fetchColumn());

        $disabled = $repository->disable($tenantId, 'example.target', $now);
        self::assertSame('disabled', $disabled->status);
        self::assertSame(3, (int) $this->query("SELECT authorization_revision FROM pa_tenant WHERE id = {$tenantId}")->fetchColumn());
    }
}
