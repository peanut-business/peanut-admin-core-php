<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Schema;

use DomainException;
use PeanutAdmin\Kernel\Authorization\Persistence\PdoAuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PdoAuthorizationRevisionRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;
use PeanutAdmin\Kernel\Authorization\Persistence\ProtectedResourceDefinition;
use PeanutAdmin\Kernel\Authorization\Persistence\ResourceOperationDefinition;
use PeanutAdmin\Kernel\Authorization\Persistence\TargetTypeDefinition;

require_once __DIR__ . '/DatabaseTestCase.php';

final class AuthorizationPersistenceTest extends DatabaseTestCase
{
    private const NOW = '2026-07-16 04:30:00.000';

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
    }

    public function testCatalogSynchronizesExplicitRelationsWithoutChangingOwners(): void
    {
        $catalog = new PdoAuthorizationCatalogRepository($this->database);
        $permissionId = $catalog->syncPermission(new PermissionDefinition(
            'example.work-item.read',
            'example',
            'api',
            'Read work items',
            'normal',
            '1.0.0',
        ));
        $resourceId = $catalog->syncProtectedResource(new ProtectedResourceDefinition(
            'example.work-item',
            'example',
            'Work Item',
            'business_target_owned',
            'example.work-item.provider',
            '1.0.0',
            str_repeat('a', 64),
        ));
        $targetTypeId = $catalog->syncTargetType(new TargetTypeDefinition(
            'example.project',
            'example',
            'Project',
            'example.project.resolver',
            'example.project.catalog',
            'string',
            '1.0.0',
            str_repeat('b', 64),
        ));
        $operationId = $catalog->syncResourceOperation(new ResourceOperationDefinition(
            'example.work-item',
            'list',
            'rule_filtered',
            'many_readable',
            'all',
            'deny_and_write',
            str_repeat('c', 64),
        ));
        $catalog->bindOperationPermission($operationId, $permissionId);
        $catalog->bindOperationTargetType(
            $operationId,
            $targetTypeId,
            'primary',
            'explicit',
            $permissionId,
        );

        self::assertSame(1, $resourceId);
        self::assertSame(1, (int) $this->query(
            'SELECT COUNT(*) FROM pa_resource_operation_permission',
        )->fetchColumn());
        self::assertSame(64, strlen($catalog->registryRevision()));

        $this->expectException(DomainException::class);
        $catalog->syncPermission(new PermissionDefinition(
            'example.work-item.read',
            'other-module',
            'api',
            'Hijacked permission',
            'normal',
            '1.0.0',
        ));
    }

    public function testRevisionRepositoryMakesOldAuthorizationKeysUnreachable(): void
    {
        $account = $this->insert('pa_account', [
            'display_name' => 'Member',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $tenant = $this->insert('pa_tenant', [
            'code' => 'revision-authz',
            'name' => 'Revision Authz',
            'display_name' => 'Revision Authz',
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
        $this->insert('pa_tenant_module', [
            'tenant_id' => $tenant,
            'module_key' => 'example',
            'status' => 'enabled',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        $revisions = new PdoAuthorizationRevisionRepository($this->database);
        self::assertSame(2, $revisions->bumpTenant($tenant));
        self::assertSame(2, $revisions->bumpMember($tenant, $member));
        self::assertSame(2, $revisions->bumpRole($tenant, $role));
        self::assertSame(2, $revisions->bumpTenantModule($tenant, 'example'));
    }
}
