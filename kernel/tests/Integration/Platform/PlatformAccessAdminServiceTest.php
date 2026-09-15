<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Platform;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Kernel\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Platform\Application\PlatformAccessAdminService;
use PeanutAdmin\Kernel\Platform\PlatformOperatorStatus;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class PlatformAccessAdminServiceTest extends DatabaseTestCase
{
    private const NOW = '2026-07-17 06:00:00.000';

    private PlatformAccessAdminService $service;
    private int $actorAccountId;
    private int $actorOperatorId;
    private int $controlRoleId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
        (new CorePermissionCatalogSynchronizer(
            new ThinkPhpAuthorizationCatalogRepository(),
        ))->synchronize();
        $this->actorAccountId = $this->account('Control Owner', 'owner@example.test');
        $this->actorOperatorId = $this->operator($this->actorAccountId, 'Control Owner');
        $this->controlRoleId = $this->role('platform.bootstrap-owner', 'Bootstrap Owner', true);
        $this->assignRole($this->actorOperatorId, $this->controlRoleId, null);
        $this->service = new PlatformAccessAdminService(new AuditService());
    }

    public function testOperatorLifecycleRolesAndSessionsCannotLockOutControlPlane(): void
    {
        $operator = $this->service->createOperator(
            $this->context('req_operator_create'),
            'second@example.test',
            'Second Operator',
            'Initial-password-123!',
        );
        self::assertSame('active', $operator['status']);
        self::assertSame('1', $operator['security_revision']);
        self::assertArrayNotHasKey('initial_password', $operator);
        self::assertSame('second@example.test', $operator['email']);
        self::assertTrue(password_verify(
            'Initial-password-123!',
            (string) $this->query("SELECT secret_hash FROM pa_credential WHERE account_id = {$operator['account_id']}")->fetchColumn(),
        ));

        $operator = $this->service->updateOperator(
            $this->context('req_operator_update'),
            (int) $operator['id'],
            1,
            'Second Control Operator',
            'Use the control-plane display name',
        );
        self::assertSame('2', $operator['security_revision']);

        $operator = $this->service->replaceOperatorRoles(
            $this->context('req_operator_empty_roles'),
            (int) $operator['id'],
            [],
            2,
            'Start without privileges',
        );
        self::assertSame([], $operator['role_keys']);
        self::assertSame('3', $operator['security_revision']);

        $this->expectAdminError('PLATFORM_CONTROL_ADMIN_REQUIRED', fn(): array => $this->service->transitionOperator(
            $this->context('req_operator_lockout'),
            $this->actorOperatorId,
            1,
            PlatformOperatorStatus::Suspended,
            'Unsafe self suspension',
        ));

        $operator = $this->service->replaceOperatorRoles(
            $this->context('req_operator_control_role'),
            (int) $operator['id'],
            [$this->controlRoleId],
            3,
            'Grant control-plane administration',
        );
        self::assertSame(['platform.bootstrap-owner'], $operator['role_keys']);
        self::assertSame('4', $operator['security_revision']);

        [$sessionId, $tokenId] = $this->platformSession($this->actorAccountId, $this->actorOperatorId);
        $actor = $this->service->transitionOperator(
            $this->context('req_operator_suspend'),
            $this->actorOperatorId,
            1,
            PlatformOperatorStatus::Suspended,
            'Rotate primary administrator',
        );
        self::assertSame('suspended', $actor['status']);
        self::assertSame('revoked', $this->query("SELECT status FROM pa_platform_session WHERE id = {$sessionId}")->fetchColumn());
        self::assertSame('revoked', $this->query("SELECT status FROM pa_platform_session_token WHERE id = {$tokenId}")->fetchColumn());

        $actor = $this->service->transitionOperator(
            $this->context(
                'req_operator_activate',
                (int) $operator['account_id'],
                (int) $operator['id'],
            ),
            $this->actorOperatorId,
            2,
            PlatformOperatorStatus::Active,
            'Return primary administrator',
        );
        self::assertSame('active', $actor['status']);

        $operator = $this->service->transitionOperator(
            $this->context('req_operator_close'),
            (int) $operator['id'],
            4,
            PlatformOperatorStatus::Closed,
            'Retire second administrator',
        );
        self::assertSame('closed', $operator['status']);
        $this->expectAdminError('PLATFORM_CONTROL_ADMIN_REQUIRED', fn(): array => $this->service->transitionOperator(
            $this->context('req_operator_final_close'),
            $this->actorOperatorId,
            3,
            PlatformOperatorStatus::Closed,
            'Unsafe final close',
        ));

        self::assertSame(7, (int) $this->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
    }

    public function testRolePermissionsStayPlatformOnlyAndCannotRemoveLastAdministrator(): void
    {
        $mutableControlRoleId = $this->role('platform.control-owner', 'Control Owner');
        $this->assignControlPermissions($mutableControlRoleId);
        $this->assignRole($this->actorOperatorId, $mutableControlRoleId, null);
        $this->database->prepare(<<<'SQL'
DELETE FROM pa_platform_operator_role
WHERE platform_operator_id = :operator_id AND platform_role_id = :role_id
SQL)->execute(['operator_id' => $this->actorOperatorId, 'role_id' => $this->controlRoleId]);
        $this->controlRoleId = $mutableControlRoleId;

        $role = $this->service->createRole(
            $this->context('req_role_create'),
            'platform.tenant-auditor',
            'Tenant Auditor',
            'Reads tenant governance records.',
        );
        self::assertSame('1', $role['revision']);

        $role = $this->service->updateRole(
            $this->context('req_role_update'),
            (int) $role['id'],
            1,
            'Tenant Governance Auditor',
            'Reads governance records only.',
            'Clarify role purpose',
        );
        self::assertSame('2', $role['revision']);

        $this->expectAdminError('PERMISSION_NOT_ASSIGNABLE', fn(): array => $this->service->replaceRolePermissions(
            $this->context('req_role_tenant_permission'),
            (int) $role['id'],
            ['core.member.read'],
            2,
            'Attempt tenant permission',
        ));
        $role = $this->service->replaceRolePermissions(
            $this->context('req_role_permissions'),
            (int) $role['id'],
            ['platform.tenant.read'],
            2,
            'Grant tenant governance read',
        );
        self::assertSame(['platform.tenant.read'], $role['permission_keys']);
        self::assertSame('3', $role['revision']);

        $role = $this->service->archiveRole(
            $this->context('req_role_archive'),
            (int) $role['id'],
            3,
            'Auditor role retired',
        );
        self::assertSame('archived', $role['status']);

        $this->expectAdminError('PLATFORM_CONTROL_ADMIN_REQUIRED', fn(): array => $this->service->replaceRolePermissions(
            $this->context('req_role_lockout'),
            $this->controlRoleId,
            ['platform.tenant.read'],
            1,
            'Unsafe permission removal',
        ));
        self::assertSame(8, (int) $this->query(
            "SELECT COUNT(*) FROM pa_platform_role_permission WHERE platform_role_id = {$this->controlRoleId}",
        )->fetchColumn());
        self::assertSame(1, (int) $this->query(
            "SELECT revision FROM pa_platform_role WHERE id = {$this->controlRoleId}",
        )->fetchColumn());
        self::assertSame(4, (int) $this->query('SELECT COUNT(*) FROM pa_platform_audit_event')->fetchColumn());
    }

    private function assignControlPermissions(int $roleId): void
    {
        foreach ([
            'platform.operator.create',
            'platform.operator.update',
            'platform.operator.lifecycle',
            'platform.operator.role.assign',
            'platform.role.create',
            'platform.role.update',
            'platform.role.archive',
            'platform.role.permission.assign',
        ] as $permissionKey) {
            $permissionId = (int) $this->query(
                "SELECT id FROM pa_permission WHERE `key` = '{$permissionKey}'",
            )->fetchColumn();
            $this->insert('pa_platform_role_permission', [
                'platform_role_id' => $roleId,
                'permission_id' => $permissionId,
                'granted_at' => self::NOW,
            ]);
        }
    }

    private function context(
        string $requestId,
        ?int $accountId = null,
        ?int $operatorId = null,
    ): PlatformContext {
        return PlatformContext::fromTrustedAutomation(
            $accountId ?? $this->actorAccountId,
            $operatorId ?? $this->actorOperatorId,
            'platform-web',
            $requestId,
            new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
        );
    }

    private function account(string $displayName, string $email): int
    {
        $accountId = $this->insert('pa_account', [
            'display_name' => $displayName,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_credential', [
            'account_id' => $accountId,
            'kind' => 'email_password',
            'identifier_type' => 'email',
            'identifier_normalized' => $email,
            'secret_hash' => password_hash('Control-password-123!', PASSWORD_ARGON2ID),
            'verified_at' => self::NOW,
            'secret_changed_at' => self::NOW,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        return $accountId;
    }

    private function operator(int $accountId, string $displayName): int
    {
        return $this->insert('pa_platform_operator', [
            'account_id' => $accountId,
            'display_name' => $displayName,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function role(string $key, string $name, bool $builtin = false): int
    {
        return $this->insert('pa_platform_role', [
            'key' => $key,
            'name' => $name,
            'is_builtin' => $builtin ? 1 : 0,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function assignRole(int $operatorId, int $roleId, ?int $assignerId): void
    {
        $this->insert('pa_platform_operator_role', [
            'platform_operator_id' => $operatorId,
            'platform_role_id' => $roleId,
            'assigned_by_operator_id' => $assignerId,
            'assigned_at' => self::NOW,
        ]);
    }

    /** @return array{int, int} */
    private function platformSession(int $accountId, int $operatorId): array
    {
        $sessionId = $this->insert('pa_platform_session', [
            'session_key' => '01J00000000000000000000000',
            'account_id' => $accountId,
            'platform_operator_id' => $operatorId,
            'client_key' => 'platform-web',
            'status' => 'active',
            'account_security_revision' => 1,
            'operator_security_revision' => 1,
            'issued_at' => self::NOW,
            'last_seen_at' => self::NOW,
            'idle_expires_at' => '2027-07-17 06:00:00.000',
            'absolute_expires_at' => '2027-07-18 06:00:00.000',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $tokenId = $this->insert('pa_platform_session_token', [
            'session_id' => $sessionId,
            'token_type' => 'access',
            'token_hash' => hash('sha256', 'platform-access-token'),
            'status' => 'active',
            'expires_at' => '2027-07-17 06:15:00.000',
            'created_at' => self::NOW,
        ]);

        return [$sessionId, $tokenId];
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
