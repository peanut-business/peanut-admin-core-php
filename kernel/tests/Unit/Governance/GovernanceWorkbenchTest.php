<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Governance;

use PeanutAdmin\Kernel\Audit\GovernanceAuditMetadata;
use PeanutAdmin\Kernel\Authorization\Governance\GovernanceException;
use PeanutAdmin\Kernel\Authorization\Governance\GovernancePermission;
use PeanutAdmin\Kernel\Authorization\Governance\GovernancePermissionCatalog;
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
                new GovernanceRoute('tenant.roles.list', '/app/roles', 'tenant', null, ['core.role.read'], 'core.role.list', ['admin-web']),
                new GovernanceRoute('example.report.list', '/app/reports', 'tenant', 'example.report', ['example.report.read'], 'example.report.page', ['admin-web']),
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
            [new GovernanceRoute('platform.roles.list', '/platform/roles', 'platform', null, ['platform.role.read'], 'platform.role.list', ['admin-web'])],
            $permissions,
            new MenuIconRegistry(['Shield']),
        );
    }

    public function testRouteContractsRejectNonCanonicalAndMismatchedDeclarations(): void
    {
        $permissions = new GovernancePermissionCatalog([
            new GovernancePermission('core.role.read', 'core', 'tenant', true),
            new GovernancePermission('example.report.read', 'example.report', 'tenant', true),
            new GovernancePermission('core.role.retired', 'core', 'tenant', false),
        ]);

        foreach (['/app/../roles', '/app/%2e%2e/roles', '/app/roles?x=1', '/app/roles#x', '/app//roles', '/app\\roles', '/app/roles/', '/platform/roles'] as $path) {
            $this->assertGovernanceFailure(
                'GOVERNANCE_ROUTE_INVALID',
                static fn() => new GovernanceRoute('tenant.roles.invalid', $path, 'tenant', null, ['core.role.read'], 'core.role.list', ['admin-web']),
            );
        }

        $this->assertGovernanceFailure('GOVERNANCE_ROUTE_CONFLICT', static fn() => new MenuGovernance([], [
            new GovernanceRoute('tenant.roles.list', '/app/roles', 'tenant', null, ['core.role.read'], 'core.role.list', ['admin-web']),
            new GovernanceRoute('tenant.roles.alias', '/app/roles', 'tenant', null, ['core.role.read'], 'core.role.list', ['admin-web']),
        ], $permissions, new MenuIconRegistry([])));

        $this->assertGovernanceFailure('GOVERNANCE_PERMISSION_INACTIVE', static fn() => new MenuGovernance([], [
            new GovernanceRoute('tenant.roles.retired', '/app/retired', 'tenant', null, ['core.role.retired'], 'core.role.list', ['admin-web']),
        ], $permissions, new MenuIconRegistry([])));

        $this->assertGovernanceFailure('GOVERNANCE_ROUTE_CONTRACT_MISMATCH', static fn() => new MenuGovernance([
            new MenuDefinition('core.roles', 'core', 'tenant', null, 'page', 'Roles', 'tenant.roles.list', '/app/roles', 'server.injected', 'core.role.read', ['admin-web'], 1, 'Shield'),
        ], [
            new GovernanceRoute('tenant.roles.list', '/app/roles', 'tenant', null, ['core.role.read'], 'core.role.list', ['admin-web']),
        ], $permissions, new MenuIconRegistry(['Shield'])));
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

        $this->assertGovernanceFailure(
            'AUDIT_METADATA_ALLOWLIST_INVALID',
            static fn() => new GovernanceAuditMetadata(['revision', 'raw_target_set']),
            \InvalidArgumentException::class,
        );
    }

    /**
     * @param callable(): mixed $operation
     * @param class-string<\Throwable> $class
     */
    private function assertGovernanceFailure(string $code, callable $operation, string $class = GovernanceException::class): void
    {
        try {
            $operation();
            self::fail("Expected {$code}.");
        } catch (\Throwable $exception) {
            self::assertInstanceOf($class, $exception);
            $actual = $exception instanceof GovernanceException ? $exception->errorCode : $exception->getMessage();
            self::assertSame($code, $actual);
        }
    }
}
