<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Module;

use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\TenantModuleConfigurationService;
use PeanutAdmin\Kernel\Module\TenantModuleConfigValidator;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class TenantModuleConfigurationServiceTest extends DatabaseTestCase
{
    private const NOW = '2026-07-17 03:00:00.000';

    public function testUpdateValidatesRevisionBumpsAuthorizationAndAudits(): void
    {
        $this->runner->migrate();
        $tenantId = $this->insert('pa_tenant', [
            'code' => 'module-config',
            'name' => 'Module Config',
            'display_name' => 'Module Config',
            'status' => 'active',
            'activated_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $accountId = $this->insert('pa_account', [
            'display_name' => 'Administrator',
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $memberId = $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'status' => 'active',
            'joined_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_module_installation', [
            'module_key' => 'example.configured',
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => str_repeat('a', 64),
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_tenant_module', [
            'tenant_id' => $tenantId,
            'module_key' => 'example.configured',
            'status' => 'enabled',
            'source' => 'manual',
            'config_json' => '{}',
            'enabled_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $validator = new class implements TenantModuleConfigValidator {
            public int $calls = 0;

            public function assertValid(ManifestDocument $manifest, array $config): void
            {
                ++$this->calls;
            }
        };
        $service = new TenantModuleConfigurationService(
            $this->database,
            $this->registry(),
            $validator,
        );

        $result = $service->update(
            $tenantId,
            'example.configured',
            ['mode' => 'strict'],
            1,
            $memberId,
            $accountId,
            'req_module_config',
        );

        self::assertSame('2', $result['revision']);
        self::assertSame(['mode' => 'strict'], $result['config']);
        self::assertSame(2, (int) $this->query("SELECT authorization_revision FROM pa_tenant WHERE id = {$tenantId}")->fetchColumn());
        self::assertSame(1, (int) $this->query("SELECT COUNT(*) FROM pa_tenant_audit_event WHERE request_id = 'req_module_config'")->fetchColumn());
        self::assertSame(1, $validator->calls);

        try {
            $service->update($tenantId, 'example.configured', ['mode' => 'strict'], 1, $memberId, $accountId, 'req_stale');
        } catch (AdminAccessException $exception) {
            self::assertSame('REVISION_MISMATCH', $exception->errorCode);

            return;
        }

        self::fail('A stale module configuration revision must be rejected.');
    }

    private function registry(): CompiledModuleRegistry
    {
        $manifest = ManifestDocument::fromArray('/tmp/example-configured', [
            'key' => 'example.configured',
            'backend' => [],
            'tenant' => ['requires' => []],
        ]);

        return new CompiledModuleRegistry([$manifest], [], [], [], $manifest->digest);
    }
}
