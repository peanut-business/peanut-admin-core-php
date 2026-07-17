<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Platform;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\Persistence\PdoModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleConfigValidator;
use PeanutAdmin\Kernel\Module\TenantModuleManager;
use PeanutAdmin\Kernel\Platform\Application\PlatformTenantAdminService;
use PeanutAdmin\Kernel\Tenancy\TenantStatus;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class PlatformTenantAdminServiceTest extends DatabaseTestCase
{
    private const NOW = '2026-07-17 05:00:00.000';

    private PlatformTenantAdminService $service;
    private int $operatorId;
    private int $operatorAccountId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
        $this->operatorAccountId = $this->account('Platform Operator');
        $this->operatorId = $this->insert('pa_platform_operator', [
            'account_id' => $this->operatorAccountId,
            'display_name' => 'Platform Operator',
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->service = new PlatformTenantAdminService(
            $this->database,
            new TenantModuleManager(
                $this->registry(),
                new PdoModuleRuntimeRepository($this->database),
                new class implements TenantModuleConfigValidator {
                    public function assertValid(ManifestDocument $manifest, array $config): void
                    {
                        if (($config['valid'] ?? false) !== true) {
                            throw new ModuleException('MODULE_CONFIG_INVALID', 'Invalid integration config.');
                        }
                    }
                },
            ),
        );
    }

    public function testCreatesUpdatesAndTransitionsTenantWithOwnerAndRevisionGuards(): void
    {
        $tenant = $this->service->createTenant(
            $this->operatorId,
            $this->operatorAccountId,
            'alpha-company',
            'Alpha Company',
            'Alpha',
            'zh-CN',
            'Asia/Shanghai',
            'req_tenant_create',
        );

        self::assertSame('provisioning', $tenant['status']);
        self::assertSame('1', $tenant['revision']);
        self::assertSame(1, (int) $this->query(
            "SELECT COUNT(*) FROM pa_role WHERE tenant_id = {$tenant['id']}"
            . " AND `key` = 'core.tenant-owner' AND is_builtin = 1",
        )->fetchColumn());
        self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM pa_department')->fetchColumn());

        $tenant = $this->service->updateTenant(
            $this->operatorId,
            $this->operatorAccountId,
            (int) $tenant['id'],
            1,
            'Alpha Company Limited',
            'Alpha Limited',
            'en-US',
            'UTC',
            'Correct legal name',
            'req_tenant_update',
        );
        self::assertSame('2', $tenant['revision']);
        self::assertSame('Alpha Limited', $tenant['display_name']);

        $this->expectAdminError('REVISION_MISMATCH', fn(): array => $this->service->updateTenant(
            $this->operatorId,
            $this->operatorAccountId,
            (int) $tenant['id'],
            1,
            'Stale',
            'Stale',
            'zh-CN',
            'Asia/Shanghai',
            'Stale write',
            'req_tenant_stale',
        ));
        $this->expectAdminError('TENANT_OWNER_REQUIRED', fn(): array => $this->service->transitionTenant(
            $this->operatorId,
            $this->operatorAccountId,
            (int) $tenant['id'],
            2,
            TenantStatus::Active,
            'Open tenant',
            'req_tenant_activate_without_owner',
        ));

        $this->activateOwner((int) $tenant['id']);
        foreach ([
            [TenantStatus::Active, 2, 'req_tenant_activate'],
            [TenantStatus::Suspended, 3, 'req_tenant_suspend'],
            [TenantStatus::Active, 4, 'req_tenant_reactivate'],
            [TenantStatus::Closed, 5, 'req_tenant_close'],
        ] as [$status, $revision, $requestId]) {
            $tenant = $this->service->transitionTenant(
                $this->operatorId,
                $this->operatorAccountId,
                (int) $tenant['id'],
                $revision,
                $status,
                'Lifecycle test',
                $requestId,
            );
        }

        self::assertSame('closed', $tenant['status']);
        self::assertSame('6', $tenant['revision']);
        self::assertSame(6, (int) $this->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
        self::assertSame(4, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
        self::assertSame(4, (int) $this->query(
            "SELECT COUNT(*) FROM pa_tenant_audit_event WHERE actor_type = 'platform_operator'",
        )->fetchColumn());
    }

    public function testTenantModuleGovernanceUsesManagerPeriodsAndDualAudit(): void
    {
        $tenant = $this->activeTenant();
        $this->installModule('example.target');
        $this->installModule('example.work-item');
        $effectiveAt = new DateTimeImmutable('2020-07-17T04:00:00Z');
        $expiresAt = new DateTimeImmutable('2030-08-17T04:00:00Z');

        $target = $this->service->enableModule(
            $this->operatorId,
            $this->operatorAccountId,
            (int) $tenant['id'],
            'example.target',
            ['valid' => true],
            'manual',
            $effectiveAt,
            $expiresAt,
            'Enable target support',
            'req_module_target_enable',
        );
        self::assertSame('enabled', $target['status']);
        self::assertSame('manual', $target['source']);
        self::assertSame('2020-07-17 04:00:00.000', $target['effective_at']);
        self::assertSame('2030-08-17 04:00:00.000', $target['expires_at']);
        self::assertSame(['valid' => true], $target['config']);

        $this->service->enableModule(
            $this->operatorId,
            $this->operatorAccountId,
            (int) $tenant['id'],
            'example.work-item',
            ['valid' => true],
            'manual',
            null,
            null,
            'Enable work items',
            'req_module_work_enable',
        );
        $this->expectAdminError('MODULE_DEPENDENT_ACTIVE', fn(): array => $this->service->disableModule(
            $this->operatorId,
            $this->operatorAccountId,
            (int) $tenant['id'],
            'example.target',
            'Disable dependency too early',
            'req_module_target_blocked',
        ));

        $this->service->disableModule(
            $this->operatorId,
            $this->operatorAccountId,
            (int) $tenant['id'],
            'example.work-item',
            'Disable work items',
            'req_module_work_disable',
        );
        $target = $this->service->disableModule(
            $this->operatorId,
            $this->operatorAccountId,
            (int) $tenant['id'],
            'example.target',
            'Disable target support',
            'req_module_target_disable',
        );

        self::assertSame('disabled', $target['status']);
        self::assertSame(6, (int) $this->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
        self::assertSame(5, (int) $this->query('SELECT COUNT(*) FROM pa_tenant_audit_event')->fetchColumn());
        self::assertSame(0, (int) $this->query(<<<'SQL'
SELECT COUNT(*) FROM pa_platform_audit_event
WHERE JSON_EXTRACT(before_json, '$.config') IS NOT NULL
   OR JSON_EXTRACT(after_json, '$.config') IS NOT NULL
SQL)->fetchColumn());
        self::assertSame(0, (int) $this->query(<<<'SQL'
SELECT COUNT(*) FROM pa_tenant_audit_event
WHERE JSON_EXTRACT(before_json, '$.config') IS NOT NULL
   OR JSON_EXTRACT(after_json, '$.config') IS NOT NULL
SQL)->fetchColumn());
    }

    /** @return array<string, mixed> */
    private function activeTenant(): array
    {
        $tenant = $this->service->createTenant(
            $this->operatorId,
            $this->operatorAccountId,
            'module-tenant',
            'Module Tenant',
            'Module Tenant',
            'zh-CN',
            'Asia/Shanghai',
            'req_module_tenant_create',
        );
        $this->activateOwner((int) $tenant['id']);

        return $this->service->transitionTenant(
            $this->operatorId,
            $this->operatorAccountId,
            (int) $tenant['id'],
            1,
            TenantStatus::Active,
            'Activate module tenant',
            'req_module_tenant_activate',
        );
    }

    private function activateOwner(int $tenantId): void
    {
        $accountId = $this->account('Tenant Owner');
        $memberId = $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'display_name' => 'Tenant Owner',
            'status' => 'active',
            'joined_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $roleId = (int) $this->query(
            "SELECT id FROM pa_role WHERE tenant_id = {$tenantId} AND `key` = 'core.tenant-owner'",
        )->fetchColumn();
        $this->insert('pa_member_role', [
            'tenant_id' => $tenantId,
            'tenant_member_id' => $memberId,
            'role_id' => $roleId,
            'assigned_at' => self::NOW,
        ]);
    }

    private function account(string $displayName): int
    {
        return $this->insert('pa_account', [
            'display_name' => $displayName,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function installModule(string $moduleKey): void
    {
        $this->insert('pa_module_installation', [
            'module_key' => $moduleKey,
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => hash('sha256', $moduleKey),
            'status' => 'active',
            'installed_at' => self::NOW,
            'activated_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function registry(): CompiledModuleRegistry
    {
        $target = ManifestDocument::fromArray('/tmp/example-target', [
            'key' => 'example.target',
            'tenant' => ['requires' => []],
        ]);
        $workItem = ManifestDocument::fromArray('/tmp/example-work-item', [
            'key' => 'example.work-item',
            'tenant' => ['requires' => ['example.target']],
        ]);

        return new CompiledModuleRegistry(
            [$target, $workItem],
            [],
            [],
            [],
            hash('sha256', $target->digest . '|' . $workItem->digest),
        );
    }

    /** @param callable(): mixed $operation */
    private function expectAdminError(string $code, callable $operation): void
    {
        try {
            $operation();
        } catch (AdminAccessException $exception) {
            self::assertSame($code, $exception->errorCode);

            return;
        }

        self::fail("Expected {$code}.");
    }
}
