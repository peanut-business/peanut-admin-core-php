<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Tenancy;

use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Audit\GovernanceAuditFilter;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Kernel\Authorization\Persistence\PdoAuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;
use PeanutAdmin\Kernel\Tenancy\Application\TenantWorkspaceQueryService;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class TenantWorkspaceQueryServiceTest extends DatabaseTestCase
{
    private const NOW = '2026-07-17 02:00:00.000';

    public function testQueriesStayInsideTenantAndExposeOnlyAvailableModuleCatalog(): void
    {
        $this->runner->migrate();
        $catalog = new PdoAuthorizationCatalogRepository($this->database);
        (new CorePermissionCatalogSynchronizer($catalog))->synchronize();
        $catalog->syncPermission(new PermissionDefinition(
            'example.target.read',
            'example.target',
            'api',
            'Read example targets',
            'normal',
            '1.0.0',
        ));
        $tenantId = $this->insert('pa_tenant', [
            'code' => 'workspace-alpha',
            'name' => 'Workspace Alpha',
            'display_name' => 'Workspace Alpha',
            'status' => 'active',
            'activated_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_module_installation', [
            'module_key' => 'example.target',
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => str_repeat('a', 64),
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_tenant_module', [
            'tenant_id' => $tenantId,
            'module_key' => 'example.target',
            'status' => 'enabled',
            'source' => 'manual',
            'config_json' => '{"mode":"fixture"}',
            'enabled_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $eventId = $this->insert('pa_tenant_audit_event', [
            'tenant_id' => $tenantId,
            'event_type' => 'tenant.fixture.created',
            'action' => 'fixture.create',
            'outcome' => 'success',
            'actor_tenant_id' => $tenantId,
            'actor_type' => 'tenant_system',
            'request_id' => 'req_workspace_fixture',
            'metadata_json' => '{"resource_key":"fixture.record","operation":"read","secret":"hidden"}',
            'occurred_at' => self::NOW,
        ]);
        $otherTenantId = $this->insert('pa_tenant', [
            'code' => 'workspace-beta',
            'name' => 'Workspace Beta',
            'display_name' => 'Workspace Beta',
            'status' => 'active',
            'activated_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_tenant_audit_event', [
            'tenant_id' => $otherTenantId,
            'event_type' => 'tenant.fixture.created',
            'action' => 'fixture.create',
            'outcome' => 'success',
            'actor_tenant_id' => $otherTenantId,
            'actor_type' => 'tenant_system',
            'request_id' => 'req_workspace_fixture',
            'occurred_at' => self::NOW,
        ]);
        $service = new TenantWorkspaceQueryService($this->database);

        self::assertSame((string) $tenantId, $service->tenant($tenantId)['id']);
        self::assertContains('core.member.read', array_column($service->permissions($tenantId), 'key'));
        self::assertContains('example.target.read', array_column($service->permissions($tenantId), 'key'));
        self::assertSame(['mode' => 'fixture'], $service->modules($tenantId)[0]['config']);
        $audit = $service->auditEvents($tenantId, new PageRequest(), new GovernanceAuditFilter(
            'tenant.fixture.created',
            'fixture.create',
            AuditOutcome::Success,
            'req_workspace_fixture',
        ));
        self::assertSame(1, $audit['total']);
        self::assertSame('req_workspace_fixture', $audit['items'][0]['request_id']);
        $detail = $service->auditEvent($tenantId, (string) $eventId);
        self::assertSame(['operation' => 'read', 'resource_key' => 'fixture.record'], $detail['metadata']);
        $this->expectException(AdminAccessException::class);
        $service->auditEvent($otherTenantId, (string) $eventId);
    }
}
