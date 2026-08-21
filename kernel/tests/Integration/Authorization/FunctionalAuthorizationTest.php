<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Authorization;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\AuthorizationException;
use PeanutAdmin\Kernel\Authorization\CorePermissionCatalog;
use PeanutAdmin\Kernel\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Kernel\Authorization\PdoTenantAuthorizationRepository;
use PeanutAdmin\Kernel\Authorization\PermissionRequirement;
use PeanutAdmin\Kernel\Authorization\Persistence\PdoAuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Http\PermissionMiddleware;
use PeanutAdmin\Kernel\Platform\Authorization\PdoPlatformAuthorizationRepository;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class FunctionalAuthorizationTest extends DatabaseTestCase
{
    private const NOW = '2026-07-16 06:00:00.000';

    private PdoAuthorizationCatalogRepository $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
        $this->catalog = new PdoAuthorizationCatalogRepository($this->database);
        (new CorePermissionCatalogSynchronizer($this->catalog))->synchronize();
    }

    public function testFixedCatalogIsVersionControlledAndIdempotent(): void
    {
        $expected = [...CorePermissionCatalog::TENANT, ...CorePermissionCatalog::PLATFORM];
        $actual = $this->query('SELECT `key` FROM pa_permission ORDER BY `key`')->fetchAll(\PDO::FETCH_COLUMN);

        sort($expected);
        self::assertSame($expected, $actual);

        (new CorePermissionCatalogSynchronizer($this->catalog))->synchronize();
        self::assertSame(count($expected), (int) $this->query('SELECT COUNT(*) FROM pa_permission')->fetchColumn());
        self::assertSame('sensitive', $this->query(
            "SELECT risk_level FROM pa_permission WHERE `key` = 'core.member.effective-access.read'",
        )->fetchColumn());
    }

    public function testPreviewProjectionReturnsTheMemberAndOnlyActiveRolesInStableOrder(): void
    {
        [$tenantId, $memberId] = $this->tenantMember('preview-subject');
        $roleB = $this->tenantRole($tenantId, $memberId, 'tenant.preview-b');
        $roleA = $this->tenantRole($tenantId, $memberId, 'tenant.preview-a');
        $this->database->exec(
            "UPDATE pa_role SET status = 'disabled' WHERE id = {$roleB}",
        );

        $repository = new PdoTenantAuthorizationRepository($this->database);
        $member = $repository->member($tenantId, $memberId);

        self::assertSame($memberId, $member['id'] ?? null);
        self::assertSame('active', $member['status']);
        self::assertSame([
            [
                'id' => $roleA,
                'key' => 'tenant.preview-a',
                'name' => 'tenant.preview-a',
                'is_builtin' => false,
            ],
        ], $repository->activeRoles($tenantId, $memberId));
        self::assertNull($repository->member($tenantId + 1, $memberId));
    }

    public function testTenantRbacRequiresAnActiveRoleAndAvailableModule(): void
    {
        [$tenantId, $memberId] = $this->tenantMember('tenant-rbac');
        $roleId = $this->tenantRole($tenantId, $memberId, 'manager');
        $corePermissionId = $this->permissionId('core.member.read');
        $modulePermissionId = $this->catalog->syncPermission(new PermissionDefinition(
            'example.record.read',
            'example.records',
            'api',
            'Read example records',
            'normal',
            '1.0.0',
        ));
        $platformPermissionId = $this->permissionId('platform.tenant.read');
        $this->grantTenantPermission($tenantId, $roleId, $corePermissionId);
        $this->grantTenantPermission($tenantId, $roleId, $modulePermissionId);
        $this->grantTenantPermission($tenantId, $roleId, $platformPermissionId);
        $this->insert('pa_module_installation', [
            'module_key' => 'example.records',
            'installed_version' => '1.0.0',
            'manifest_schema_version' => 1,
            'manifest_digest' => hash('sha256', 'example.records'),
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_tenant_module', [
            'tenant_id' => $tenantId,
            'module_key' => 'example.records',
            'status' => 'disabled',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        $evaluator = $this->tenantEvaluator();
        $context = $this->tenantContext($tenantId, $memberId);
        self::assertTrue($evaluator->allows($context, 'core.member.read'));
        self::assertFalse($evaluator->allows($context, 'example.record.read'));
        self::assertFalse($evaluator->allows($context, 'platform.tenant.read'));

        $this->database->exec(<<<'SQL'
        UPDATE pa_tenant_module
        SET status = 'enabled', authorization_revision = authorization_revision + 1
        WHERE module_key = 'example.records'
        SQL);
        self::assertTrue($evaluator->allows($context, 'example.record.read'));

        $this->database->exec(<<<'SQL'
        UPDATE pa_module_installation
        SET status = 'maintenance', revision = revision + 1
        WHERE module_key = 'example.records'
        SQL);
        self::assertFalse($evaluator->allows($context, 'example.record.read'));

        $this->database->exec(<<<'SQL'
        UPDATE pa_module_installation
        SET status = 'active', revision = revision + 1
        WHERE module_key = 'example.records'
        SQL);
        self::assertTrue($evaluator->allows($context, 'example.record.read'));

        $this->database->exec("UPDATE pa_role SET status = 'disabled', authorization_revision = authorization_revision + 1 WHERE id = {$roleId}");
        self::assertFalse($evaluator->allows($context, 'core.member.read'));
    }

    public function testTenantOwnerReceivesOnlyFixedTenantCorePermissions(): void
    {
        [$tenantId, $memberId] = $this->tenantMember('tenant-owner');
        $this->tenantRole($tenantId, $memberId, 'core.tenant-owner');
        $this->catalog->syncPermission(new PermissionDefinition(
            'example.record.manage',
            'example.records',
            'api',
            'Manage example records',
            'sensitive',
            '1.0.0',
        ));
        $this->insert('pa_tenant_module', [
            'tenant_id' => $tenantId,
            'module_key' => 'example.records',
            'status' => 'enabled',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        $evaluator = $this->tenantEvaluator();
        $context = $this->tenantContext($tenantId, $memberId);
        foreach (CorePermissionCatalog::TENANT as $permission) {
            self::assertTrue($evaluator->allows($context, $permission));
        }
        self::assertFalse($evaluator->allows($context, 'example.record.manage'));
    }

    public function testOwnerKeyWithoutBuiltinMarkerHasNoImplicitPermissions(): void
    {
        [$tenantId, $memberId] = $this->tenantMember('tenant-fake-owner');
        $roleId = $this->insert('pa_role', [
            'tenant_id' => $tenantId,
            'key' => 'core.tenant-owner',
            'name' => 'Fake owner',
            'is_builtin' => 0,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_member_role', [
            'tenant_id' => $tenantId,
            'tenant_member_id' => $memberId,
            'role_id' => $roleId,
            'assigned_at' => self::NOW,
        ]);

        self::assertFalse($this->tenantEvaluator()->allows(
            $this->tenantContext($tenantId, $memberId),
            'core.member.read',
        ));
    }

    public function testRoleRevisionMakesAnOldPermissionCacheEntryUnreachable(): void
    {
        [$tenantId, $memberId] = $this->tenantMember('tenant-revision');
        $roleId = $this->tenantRole($tenantId, $memberId, 'editor');
        $evaluator = $this->tenantEvaluator();
        $context = $this->tenantContext($tenantId, $memberId);
        self::assertFalse($evaluator->allows($context, 'core.member.update'));

        $this->grantTenantPermission($tenantId, $roleId, $this->permissionId('core.member.update'));
        $this->database->exec("UPDATE pa_role SET authorization_revision = authorization_revision + 1 WHERE id = {$roleId}");

        self::assertTrue($evaluator->allows($context, 'core.member.update'));
    }

    public function testPlatformRbacNeverAcceptsTenantPermissions(): void
    {
        $accountId = $this->account('Platform operator');
        $operatorId = $this->insert('pa_platform_operator', [
            'account_id' => $accountId,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $roleId = $this->insert('pa_platform_role', [
            'key' => 'platform.support',
            'name' => 'Support',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->assignPlatformRole($operatorId, $roleId);
        $this->grantPlatformPermission($roleId, $this->permissionId('platform.tenant.read'));
        $this->grantPlatformPermission($roleId, $this->permissionId('core.member.read'));

        $evaluator = $this->platformEvaluator();
        $context = $this->platformContext($accountId, $operatorId);
        self::assertTrue($evaluator->allows($context, 'platform.tenant.read'));
        self::assertFalse($evaluator->allows($context, 'core.member.read'));

        $ownerRoleId = $this->insert('pa_platform_role', [
            'key' => 'platform.bootstrap-owner',
            'name' => 'Platform owner',
            'is_builtin' => 1,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->assignPlatformRole($operatorId, $ownerRoleId);
        $this->database->exec("UPDATE pa_platform_operator SET security_revision = security_revision + 1 WHERE id = {$operatorId}");
        foreach (CorePermissionCatalog::PLATFORM as $permission) {
            self::assertTrue($evaluator->allows($context, $permission));
        }
    }

    public function testPermissionMiddlewareUsesExplicitAudienceAndMatchRule(): void
    {
        [$tenantId, $memberId] = $this->tenantMember('tenant-middleware');
        $roleId = $this->tenantRole($tenantId, $memberId, 'reader');
        $this->grantTenantPermission($tenantId, $roleId, $this->permissionId('core.member.read'));
        $middleware = new PermissionMiddleware($this->tenantEvaluator(), $this->platformEvaluator());
        $context = $this->tenantContext($tenantId, $memberId);

        $middleware->authorizeTenant($context, new PermissionRequirement(
            'tenant',
            ['core.member.read', 'core.member.update'],
            'any',
        ));
        self::addToAssertionCount(1);

        $this->expectException(AuthorizationException::class);
        $middleware->authorizeTenant($context, new PermissionRequirement(
            'tenant',
            ['core.member.read', 'core.member.update'],
        ));
    }

    /** @return array{int, int} */
    private function tenantMember(string $code): array
    {
        $accountId = $this->account($code);
        $tenantId = $this->insert('pa_tenant', [
            'code' => $code,
            'name' => $code,
            'display_name' => $code,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $memberId = $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return [$tenantId, $memberId];
    }

    private function account(string $name): int
    {
        return $this->insert('pa_account', [
            'display_name' => $name,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function tenantRole(int $tenantId, int $memberId, string $key): int
    {
        $roleId = $this->insert('pa_role', [
            'tenant_id' => $tenantId,
            'key' => $key,
            'name' => $key,
            'is_builtin' => str_starts_with($key, 'core.') ? 1 : 0,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_member_role', [
            'tenant_id' => $tenantId,
            'tenant_member_id' => $memberId,
            'role_id' => $roleId,
            'assigned_at' => self::NOW,
        ]);

        return $roleId;
    }

    private function permissionId(string $key): int
    {
        $statement = $this->database->prepare('SELECT id FROM pa_permission WHERE `key` = :permission_key');
        $statement->execute(['permission_key' => $key]);

        return (int) $statement->fetchColumn();
    }

    private function grantTenantPermission(int $tenantId, int $roleId, int $permissionId): void
    {
        $this->insert('pa_role_permission', [
            'tenant_id' => $tenantId,
            'role_id' => $roleId,
            'permission_id' => $permissionId,
            'granted_at' => self::NOW,
        ]);
    }

    private function assignPlatformRole(int $operatorId, int $roleId): void
    {
        $this->insert('pa_platform_operator_role', [
            'platform_operator_id' => $operatorId,
            'platform_role_id' => $roleId,
            'assigned_at' => self::NOW,
        ]);
    }

    private function grantPlatformPermission(int $roleId, int $permissionId): void
    {
        $this->insert('pa_platform_role_permission', [
            'platform_role_id' => $roleId,
            'permission_id' => $permissionId,
            'granted_at' => self::NOW,
        ]);
    }

    private function tenantContext(int $tenantId, int $memberId): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            'session',
            $tenantId,
            1,
            $memberId,
            'web',
            new DateTimeImmutable(self::NOW),
            1,
        ), 'request');
    }

    private function platformContext(int $accountId, int $operatorId): PlatformContext
    {
        return PlatformContext::fromValidatedSession(new ValidatedPlatformSession(
            1,
            'platform-session',
            $accountId,
            $operatorId,
            'web',
            new DateTimeImmutable(self::NOW),
        ), 'request');
    }

    private function tenantEvaluator(): TenantAuthorizationEvaluator
    {
        return new TenantAuthorizationEvaluator(
            new PdoTenantAuthorizationRepository($this->database),
            new RevisionPermissionCache(),
        );
    }

    private function platformEvaluator(): PlatformAuthorizationEvaluator
    {
        return new PlatformAuthorizationEvaluator(
            new PdoPlatformAuthorizationRepository($this->database),
            new RevisionPermissionCache(),
        );
    }
}
