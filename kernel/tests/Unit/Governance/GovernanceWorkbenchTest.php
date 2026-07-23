<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Governance;

use PeanutAdmin\Kernel\Authorization\Governance\GovernanceException;
use PeanutAdmin\Kernel\Authorization\Governance\GovernancePermission;
use PeanutAdmin\Kernel\Authorization\Governance\GovernancePermissionCatalog;
use PeanutAdmin\Kernel\Authorization\Governance\GovernanceResourceOperation;
use PeanutAdmin\Kernel\Authorization\Governance\RoleDataPolicyGovernance;
use PeanutAdmin\Kernel\Authorization\Governance\RolePermissionGovernance;
use PeanutAdmin\Kernel\Audit\GovernanceAuditMetadata;
use PeanutAdmin\Kernel\Menu\GovernanceRoute;
use PeanutAdmin\Kernel\Menu\MenuDefinition;
use PeanutAdmin\Kernel\Menu\MenuGovernance;
use PeanutAdmin\Kernel\Menu\MenuIconRegistry;
use PHPUnit\Framework\TestCase;

final class GovernanceWorkbenchTest extends TestCase
{
    public function testCatalogAndMenuVisibilityFailClosed(): void
    {
        $permissions = new GovernancePermissionCatalog([
            new GovernancePermission('core.role.read', 'core', 'tenant', true),
            new GovernancePermission('platform.role.read', 'platform', 'platform', true),
            new GovernancePermission('example.report.read', 'example.report', 'tenant', true),
        ]);
        $governance = new MenuGovernance(
            [
                new MenuDefinition('core.group', 'core', 'tenant', null, 'group', 'Governance', null, null, null, null, ['admin-web'], 1, 'Shield'),
                new MenuDefinition('core.roles', 'core', 'tenant', 'core.group', 'page', 'Roles', 'tenant.roles.list', '/app/roles', 'core.role.list', 'core.role.read', ['admin-web'], 2, 'Shield'),
                new MenuDefinition('example.report', 'example.report', 'tenant', 'core.group', 'page', 'Reports', 'example.report.list', '/app/reports', 'example.report.page', 'example.report.read', ['admin-web'], 3, 'Files'),
            ],
            [
                new GovernanceRoute('tenant.roles.list', '/app/roles', 'tenant', null, ['core.role.read']),
                new GovernanceRoute('example.report.list', '/app/reports', 'tenant', 'example.report', ['example.report.read']),
            ],
            $permissions,
            new MenuIconRegistry(['Shield', 'Files']),
        );

        $visible = $governance->explain('tenant', 'admin-web', ['example.report'], [], ['core.role.read', 'example.report.read']);
        self::assertTrue($visible['core.roles']->visible);
        self::assertSame('tenant_module_disabled', $visible['example.report']->reason);

        $enabled = $governance->explain('tenant', 'admin-web', ['example.report'], ['example.report'], ['core.role.read', 'example.report.read']);
        self::assertTrue($enabled['example.report']->visible);

        $this->expectException(GovernanceException::class);
        new MenuGovernance(
            [new MenuDefinition('bad', 'core', 'tenant', null, 'page', 'Bad', 'platform.roles.list', '/app/bad', 'bad.page', 'core.role.read', ['admin-web'], 1, 'Shield')],
            [new GovernanceRoute('platform.roles.list', '/platform/roles', 'platform', null, ['platform.role.read'])],
            $permissions,
            new MenuIconRegistry(['Shield']),
        );
    }

    public function testRolePermissionAndDataPolicyChangesRequireDeclaredCatalogAndRevision(): void
    {
        $permissions = new GovernancePermissionCatalog([
            new GovernancePermission('core.role.read', 'core', 'tenant', true),
            new GovernancePermission('example.report.read', 'example.report', 'tenant', true),
            new GovernancePermission('platform.role.read', 'platform', 'platform', true),
        ]);
        $change = (new RolePermissionGovernance($permissions))->prepare(
            'tenant',
            9,
            4,
            '"rev-4"',
            ['example.report.read', 'core.role.read', 'core.role.read'],
            ['example.report'],
        );
        self::assertSame(['core.role.read', 'example.report.read'], $change->permissionKeys);

        $policy = (new RoleDataPolicyGovernance([
            new GovernanceResourceOperation('example.report', 'list', 'example.report', 'tenant', ['core.tenant_wide', 'core.specified_objects']),
        ]))->prepare(
            'tenant',
            9,
            7,
            '"rev-7"',
            'example.report',
            'list',
            ['core.specified_objects'],
            ['example.report'],
        );
        self::assertSame(['core.specified_objects'], $policy->conditionKeys);

        try {
            (new RolePermissionGovernance($permissions))->prepare('tenant', 9, 4, '"rev-3"', ['core.role.read'], []);
            self::fail('A stale role revision must fail closed.');
        } catch (GovernanceException $exception) {
            self::assertSame('REVISION_MISMATCH', $exception->errorCode);
        }

        try {
            (new RolePermissionGovernance($permissions))->prepare('tenant', 9, 4, '"rev-4"', ['platform.role.read'], []);
            self::fail('Cross-audience permissions must fail closed.');
        } catch (GovernanceException $exception) {
            self::assertSame('GOVERNANCE_PERMISSION_AUDIENCE_MISMATCH', $exception->errorCode);
        }
    }

    public function testAuditMetadataUsesAnExplicitAllowlist(): void
    {
        $projector = new GovernanceAuditMetadata(['revision', 'permission_count', 'status']);
        self::assertSame([
            'permission_count' => 3,
            'revision' => 7,
            'status' => 'active',
        ], $projector->project([
            'revision' => 7,
            'permission_count' => 3,
            'status' => 'active',
            'token' => 'secret-token',
            'sql' => 'SELECT * FROM hidden',
            'raw_target_set' => ['101'],
        ]));
    }
}
