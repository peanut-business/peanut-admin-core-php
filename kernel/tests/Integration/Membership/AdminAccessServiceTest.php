<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Membership;

use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Authorization\Application\RoleAdminService;
use PeanutAdmin\Kernel\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Kernel\Authorization\Persistence\PdoAuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;
use PeanutAdmin\Kernel\Membership\Application\MemberAdminService;
use PeanutAdmin\Kernel\Organization\Application\DepartmentAdminService;
use PeanutAdmin\Kernel\Platform\Application\TenantOwnerAdminService;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class AdminAccessServiceTest extends DatabaseTestCase
{
    private const NOW = '2026-07-16 08:00:00.000';

    private int $tenantId;
    private int $actorAccountId;
    private int $actorMemberId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runner->migrate();
        (new CorePermissionCatalogSynchronizer(
            new PdoAuthorizationCatalogRepository($this->database),
        ))->synchronize();

        $this->actorAccountId = $this->account('Tenant administrator');
        $this->tenantId = $this->tenant('admin-access', 'active');
        $this->actorMemberId = $this->member($this->tenantId, $this->actorAccountId, 'active');
    }

    public function testMemberLifecycleRoleReplacementAndCrossTenantIdsFailClosed(): void
    {
        $service = $this->members();
        $candidate = $service->createPending(
            $this->tenantId,
            'new-member@example.test',
            'New member',
            'Initial-password-123!',
            $this->actorMemberId,
            $this->actorAccountId,
            'request-member-create',
        );
        self::assertSame('pending', $candidate['status']);
        self::assertSame('new-member@example.test', (string) $this->query(
            'SELECT identifier_normalized FROM pa_credential ORDER BY id DESC LIMIT 1',
        )->fetchColumn());

        $memberId = (int) $candidate['id'];
        $activated = $service->activate(
            $this->tenantId,
            $memberId,
            (int) $candidate['revision'],
            $this->actorMemberId,
            $this->actorAccountId,
            'request-member-activate',
        );
        self::assertSame('active', $activated['status']);

        $roleId = $this->role($this->tenantId, 'sales');
        $assigned = $service->replaceRoles(
            $this->tenantId,
            $memberId,
            [$roleId],
            (int) $activated['revision'],
            $this->actorMemberId,
            $this->actorAccountId,
            'request-member-roles',
        );
        self::assertSame(['sales'], $assigned['role_keys']);

        $otherTenant = $this->tenant('other-tenant', 'active');
        $otherRole = $this->role($otherTenant, 'other-role');
        try {
            $service->replaceRoles(
                $this->tenantId,
                $memberId,
                [$otherRole],
                (int) $assigned['revision'],
                $this->actorMemberId,
                $this->actorAccountId,
                'request-cross-role',
            );
            self::fail('Expected a cross-tenant role to be hidden.');
        } catch (AdminAccessException $exception) {
            self::assertSame(404, $exception->httpStatus);
        }

        $page = $service->list($this->tenantId, new PageRequest(1, 1));
        self::assertSame(2, $page['total']);
        self::assertCount(1, $page['items']);
    }

    public function testFinalActiveOwnerCannotBeSuspendedOrLoseOwnerRole(): void
    {
        $ownerRole = $this->insert('pa_role', [
            'tenant_id' => $this->tenantId,
            'key' => 'core.tenant-owner',
            'name' => 'Tenant owner',
            'is_builtin' => 1,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $this->insert('pa_member_role', [
            'tenant_id' => $this->tenantId,
            'tenant_member_id' => $this->actorMemberId,
            'role_id' => $ownerRole,
            'assigned_at' => self::NOW,
        ]);
        $member = $this->members()->get($this->tenantId, $this->actorMemberId);

        foreach (['suspend', 'replace'] as $operation) {
            try {
                if ($operation === 'suspend') {
                    $this->members()->suspend(
                        $this->tenantId,
                        $this->actorMemberId,
                        (int) $member['revision'],
                        $this->actorMemberId,
                        $this->actorAccountId,
                        'request-last-owner-suspend',
                    );
                } else {
                    $this->members()->replaceRoles(
                        $this->tenantId,
                        $this->actorMemberId,
                        [],
                        (int) $member['revision'],
                        $this->actorMemberId,
                        $this->actorAccountId,
                        'request-last-owner-role',
                    );
                }
                self::fail('Expected the final active owner guard to reject the operation.');
            } catch (AdminAccessException $exception) {
                self::assertSame('LAST_ACTIVE_OWNER_REQUIRED', $exception->errorCode);
            }
        }
    }

    public function testDepartmentTreeRejectsCyclesDepthOverflowAndStaleRevisions(): void
    {
        $service = new DepartmentAdminService($this->database);
        $parentId = null;
        $departments = [];
        for ($depth = 1; $depth <= 10; ++$depth) {
            $department = $service->create(
                $this->tenantId,
                'depth-' . $depth,
                'Depth ' . $depth,
                $parentId,
                $depth,
                $this->actorMemberId,
                $this->actorAccountId,
                'request-department-' . $depth,
            );
            $departments[] = $department;
            $parentId = (int) $department['id'];
        }

        $this->assertAdminError('DEPARTMENT_DEPTH_EXCEEDED', fn() => $service->create(
            $this->tenantId,
            'depth-11',
            'Depth 11',
            $parentId,
            11,
            $this->actorMemberId,
            $this->actorAccountId,
            'request-department-11',
        ));
        $root = $departments[0];
        $this->assertAdminError('DEPARTMENT_CYCLE', fn() => $service->move(
            $this->tenantId,
            (int) $root['id'],
            (int) $departments[1]['id'],
            (int) $root['revision'],
            $this->actorMemberId,
            $this->actorAccountId,
            'request-department-cycle',
        ));

        $updated = $service->update(
            $this->tenantId,
            (int) $root['id'],
            'root-updated',
            'Root updated',
            0,
            (int) $root['revision'],
            $this->actorMemberId,
            $this->actorAccountId,
            'request-department-update',
        );
        self::assertSame('2', $updated['revision']);
        $this->assertAdminStatus(412, fn() => $service->update(
            $this->tenantId,
            (int) $root['id'],
            'stale',
            'Stale',
            0,
            (int) $root['revision'],
            $this->actorMemberId,
            $this->actorAccountId,
            'request-department-stale',
        ));
    }

    public function testRolePermissionAssignmentRequiresAnAvailableTenantModule(): void
    {
        $service = new RoleAdminService($this->database);
        $role = $service->create(
            $this->tenantId,
            'example-reader',
            'Example reader',
            null,
            $this->actorMemberId,
            $this->actorAccountId,
            'request-role-create',
        );
        $catalog = new PdoAuthorizationCatalogRepository($this->database);
        $catalog->syncPermission(new PermissionDefinition(
            'example.record.read',
            'example.records',
            'api',
            'Read example records',
            'normal',
            '1.0.0',
        ));
        $this->insert('pa_tenant_module', [
            'tenant_id' => $this->tenantId,
            'module_key' => 'example.records',
            'status' => 'disabled',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);

        $this->assertAdminError('PERMISSION_NOT_ASSIGNABLE', fn() => $service->replacePermissions(
            $this->tenantId,
            (int) $role['id'],
            ['example.record.read'],
            (int) $role['revision'],
            $this->actorMemberId,
            $this->actorAccountId,
            'request-role-permissions-disabled',
        ));
        $this->database->exec("UPDATE pa_tenant_module SET status = 'enabled' WHERE tenant_id = {$this->tenantId} AND module_key = 'example.records'");
        $updated = $service->replacePermissions(
            $this->tenantId,
            (int) $role['id'],
            ['example.record.read'],
            (int) $role['revision'],
            $this->actorMemberId,
            $this->actorAccountId,
            'request-role-permissions',
        );
        self::assertSame(['example.record.read'], $updated['permission_keys']);

        $this->assertAdminError('PERMISSION_NOT_ASSIGNABLE', fn() => $service->replacePermissions(
            $this->tenantId,
            (int) $role['id'],
            ['platform.tenant.read'],
            (int) $updated['revision'],
            $this->actorMemberId,
            $this->actorAccountId,
            'request-platform-permission',
        ));
    }

    public function testPlatformOwnerCandidateIsSeparateLockedAndIdempotentlyActivated(): void
    {
        $operatorAccountId = $this->account('Platform operator');
        $operatorId = $this->insert('pa_platform_operator', [
            'account_id' => $operatorAccountId,
            'status' => 'active',
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $tenantId = $this->tenant('provision-owner', 'provisioning');
        $this->insert('pa_role', [
            'tenant_id' => $tenantId,
            'key' => 'core.tenant-owner',
            'name' => 'Tenant owner',
            'is_builtin' => 1,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
        $service = new TenantOwnerAdminService($this->database);
        $candidate = $service->createCandidate(
            $operatorId,
            $operatorAccountId,
            $tenantId,
            'first-owner@example.test',
            'First owner',
            'Initial-password-123!',
            'request-owner-candidate',
        );
        self::assertSame('pending', $candidate['member']['status']);
        self::assertArrayNotHasKey('initial_password', $candidate);
        self::assertSame('first-owner@example.test', (string) $this->query(
            'SELECT identifier_normalized FROM pa_credential ORDER BY id DESC LIMIT 1',
        )->fetchColumn());

        $this->assertAdminError('TENANT_OWNER_CANDIDATE_EXISTS', fn() => $service->createCandidate(
            $operatorId,
            $operatorAccountId,
            $tenantId,
            'other-owner@example.test',
            'Other owner',
            'Another-password-123!',
            'request-second-owner',
        ));

        $memberId = (int) $candidate['member']['id'];
        $revision = (int) $candidate['member']['revision'];
        $activated = $service->activateCandidate(
            $operatorId,
            $operatorAccountId,
            $tenantId,
            $memberId,
            $revision,
            'owner-activate-key',
            'Initial tenant owner confirmed',
            'request-owner-activate',
        );
        self::assertSame('active', $activated['member']['status']);

        $replayed = $service->activateCandidate(
            $operatorId,
            $operatorAccountId,
            $tenantId,
            $memberId,
            $revision,
            'owner-activate-key',
            'Initial tenant owner confirmed',
            'request-owner-activate-retry',
        );
        self::assertSame($activated['member']['revision'], $replayed['member']['revision']);
        self::assertSame(1, (int) $this->query(<<<'SQL'
SELECT COUNT(*) FROM pa_platform_audit_event WHERE event_type = 'tenant.owner-candidate.activated'
SQL)->fetchColumn());
    }

    private function members(): MemberAdminService
    {
        return new MemberAdminService($this->database);
    }

    public function testAdministratorCreateRollsBackAccountCredentialAndMembershipOnInvalidRelations(): void
    {
        $otherTenant = $this->tenant('atomic-other', 'active');
        $foreignRole = $this->role($otherTenant, 'foreign');
        $localRole = $this->role($this->tenantId, 'local');
        $departmentId = $this->insert('pa_department', [
            'tenant_id' => $otherTenant, 'code' => 'foreign', 'name' => 'Foreign',
            'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $before = $this->administrationState();

        foreach ([[null, [$foreignRole]], [$departmentId, [$localRole]]] as [$departmentId, $roles]) {
            $this->assertAdminStatus(404, fn() => $this->members()->createAdministrator(
                $this->tenantId, 'atomic-new@example.test', 'Atomic new',
                'Initial-password-123!', $departmentId, $roles, true,
                $this->actorMemberId, $this->actorAccountId, 'atomic-create-invalid',
            ));
            self::assertSame($before, $this->administrationState());
            self::assertFalse($this->database->inTransaction());
        }

        $created = $this->members()->createAdministrator(
            $this->tenantId, 'atomic-new@example.test', 'Atomic new',
            'Initial-password-123!', null, [$localRole], true,
            $this->actorMemberId, $this->actorAccountId, 'atomic-create-success',
        );
        self::assertSame('active', $created['status']);
        self::assertSame(['local'], $created['role_keys']);
        self::assertSame('Atomic new', $created['display_name']);
        self::assertFalse($this->database->inTransaction());
    }

    public function testAdministratorEditRollsBackProfileRolesRevisionsAndAuditsOnLateOwnerFailure(): void
    {
        $ownerRole = $this->insert('pa_role', [
            'tenant_id' => $this->tenantId, 'key' => 'core.tenant-owner',
            'name' => 'Tenant owner', 'is_builtin' => 1,
            'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $this->insert('pa_member_role', [
            'tenant_id' => $this->tenantId, 'tenant_member_id' => $this->actorMemberId,
            'role_id' => $ownerRole, 'assigned_at' => self::NOW,
        ]);
        $extraRole = $this->role($this->tenantId, 'extra');
        $before = $this->administrationState();
        $member = $this->members()->get($this->tenantId, $this->actorMemberId);

        // The suspension guard runs after profile and role writes and their audits.
        $this->assertAdminError('LAST_ACTIVE_OWNER_REQUIRED', fn() => $this->members()->updateAdministrator(
            $this->tenantId, $this->actorMemberId, 'Must roll back', null,
            [$ownerRole, $extraRole], false, (int) $member['revision'],
            $this->actorMemberId, $this->actorAccountId, 'atomic-edit-owner',
        ));
        self::assertSame($before, $this->administrationState());
        self::assertFalse($this->database->inTransaction());

        $this->assertAdminError('LAST_ACTIVE_OWNER_REQUIRED', fn() => $this->members()->updateAdministrator(
            $this->tenantId, $this->actorMemberId, 'Must roll back', null,
            [$extraRole], true, (int) $member['revision'],
            $this->actorMemberId, $this->actorAccountId, 'atomic-edit-remove-owner',
        ));
        self::assertSame($before, $this->administrationState());
    }

    public function testAdministratorEditPreservesRevisionAndTenantBoundaries(): void
    {
        $role = $this->role($this->tenantId, 'atomic-role');
        $created = $this->members()->createAdministrator(
            $this->tenantId, 'atomic-edit@example.test', 'Before',
            'Initial-password-123!', null, [$role], false,
            $this->actorMemberId, $this->actorAccountId, 'atomic-pending',
        );
        self::assertSame('pending', $created['status']);
        $otherTenant = $this->tenant('atomic-edit-other', 'active');
        $otherRole = $this->role($otherTenant, 'foreign-role');
        $before = $this->administrationState();

        $this->assertAdminStatus(404, fn() => $this->members()->updateAdministrator(
            $this->tenantId, (int) $created['id'], 'Must roll back', null,
            [$otherRole], true, (int) $created['revision'],
            $this->actorMemberId, $this->actorAccountId, 'atomic-edit-foreign-role',
        ));
        $this->assertAdminStatus(404, fn() => $this->members()->updateAdministrator(
            $otherTenant, (int) $created['id'], 'Must roll back', null,
            [$otherRole], true, (int) $created['revision'],
            $this->actorMemberId, $this->actorAccountId, 'atomic-edit-foreign-member',
        ));
        $this->assertAdminStatus(412, fn() => $this->members()->updateAdministrator(
            $this->tenantId, (int) $created['id'], 'Must roll back', null,
            [$role], true, (int) $created['revision'] - 1,
            $this->actorMemberId, $this->actorAccountId, 'atomic-edit-stale',
        ));
        self::assertSame($before, $this->administrationState());

        $updated = $this->members()->updateAdministrator(
            $this->tenantId, (int) $created['id'], 'After', null,
            [$role], true, (int) $created['revision'],
            $this->actorMemberId, $this->actorAccountId, 'atomic-edit-success',
        );
        self::assertSame('After', $updated['display_name']);
        self::assertSame('active', $updated['status']);
        self::assertSame(['atomic-role'], $updated['role_keys']);
    }

    /** Captures every persisted surface owned by the aggregate, including audits. */
    private function administrationState(): array
    {
        $state = [];
        foreach (['pa_account', 'pa_credential', 'pa_tenant', 'pa_tenant_member', 'pa_member_role', 'pa_tenant_audit_event'] as $table) {
            $order = $table === 'pa_member_role' ? 'tenant_id, tenant_member_id, role_id' : 'id';
            $state[$table] = $this->query("SELECT * FROM {$table} ORDER BY {$order}")->fetchAll();
        }

        return $state;
    }

    private function account(string $name): int
    {
        return $this->insert('pa_account', [
            'display_name' => $name,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function tenant(string $code, string $status): int
    {
        return $this->insert('pa_tenant', [
            'code' => $code,
            'name' => $code,
            'display_name' => $code,
            'status' => $status,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function member(int $tenantId, int $accountId, string $status): int
    {
        return $this->insert('pa_tenant_member', [
            'tenant_id' => $tenantId,
            'account_id' => $accountId,
            'status' => $status,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function role(int $tenantId, string $key): int
    {
        return $this->insert('pa_role', [
            'tenant_id' => $tenantId,
            'key' => $key,
            'name' => $key,
            'created_at' => self::NOW,
            'updated_at' => self::NOW,
        ]);
    }

    private function assertAdminError(string $errorCode, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected admin error {$errorCode}.");
        } catch (AdminAccessException $exception) {
            self::assertSame($errorCode, $exception->errorCode);
        }
    }

    private function assertAdminStatus(int $status, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected HTTP status {$status}.");
        } catch (AdminAccessException $exception) {
            self::assertSame($status, $exception->httpStatus);
        }
    }
}
