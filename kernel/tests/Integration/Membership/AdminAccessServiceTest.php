<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Membership;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PeanutAdmin\App\Tests\Support\ThinkPhpTestConnection;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Authorization\Application\RoleAdminService;
use PeanutAdmin\Kernel\Authorization\CorePermissionCatalogSynchronizer;
use PeanutAdmin\Kernel\Authorization\Persistence\ThinkPhpAuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;
use PeanutAdmin\Kernel\Membership\Application\MemberAdminService;
use PeanutAdmin\Kernel\Organization\Application\DepartmentAdminService;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Platform\Application\TenantOwnerAdminService;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;
use RuntimeException;
use Throwable;

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
            new ThinkPhpAuthorizationCatalogRepository(),
        ))->synchronize();

        $this->actorAccountId = $this->account('Tenant administrator');
        $this->tenantId = $this->tenant('admin-access', 'active');
        $this->actorMemberId = $this->member($this->tenantId, $this->actorAccountId, 'active');
    }

    public function testMemberLifecycleRoleReplacementAndCrossTenantIdsFailClosed(): void
    {
        $service = $this->members();
        $candidate = $service->createPending(
            $this->tenantContext('request-member-create'),
            'new-member@example.test',
            'New member',
            'Initial-password-123!',
        );
        self::assertSame('pending', $candidate['status']);
        self::assertSame('new-member@example.test', (string) $this->query(
            'SELECT identifier_normalized FROM pa_credential ORDER BY id DESC LIMIT 1',
        )->fetchColumn());

        $memberId = (int) $candidate['id'];
        $activated = $service->activate(
            $this->tenantContext('request-member-activate'),
            $memberId,
            (int) $candidate['revision'],
        );
        self::assertSame('active', $activated['status']);

        $roleId = $this->role($this->tenantId, 'sales');
        $assigned = $service->replaceRoles(
            $this->tenantContext('request-member-roles'),
            $memberId,
            [$roleId],
            (int) $activated['revision'],
        );
        self::assertSame(['sales'], $assigned['role_keys']);

        $otherTenant = $this->tenant('other-tenant', 'active');
        $otherRole = $this->role($otherTenant, 'other-role');
        try {
            $service->replaceRoles(
                $this->tenantContext('request-cross-role'),
                $memberId,
                [$otherRole],
                (int) $assigned['revision'],
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
                        $this->tenantContext('request-last-owner-suspend'),
                        $this->actorMemberId,
                        (int) $member['revision'],
                    );
                } else {
                    $this->members()->replaceRoles(
                        $this->tenantContext('request-last-owner-role'),
                        $this->actorMemberId,
                        [],
                        (int) $member['revision'],
                    );
                }
                self::fail('Expected the final active owner guard to reject the operation.');
            } catch (AdminAccessException $exception) {
                self::assertSame('LAST_ACTIVE_OWNER_REQUIRED', $exception->errorCode);
            }
        }
    }

    /** Forces both owner removals to contend on MySQL before either may commit. */
    public function testConcurrentOwnerRoleRemovalPreservesOneActiveOwner(): void
    {
        $ownerRole = $this->insert('pa_role', [
            'tenant_id' => $this->tenantId, 'key' => 'core.tenant-owner',
            'name' => 'Tenant owner', 'is_builtin' => 1,
            'created_at' => self::NOW, 'updated_at' => self::NOW,
        ]);
        $secondAccount = $this->account('Second owner');
        $secondMember = $this->member($this->tenantId, $secondAccount, 'active');
        $owners = [[$this->actorMemberId, $this->actorAccountId], [$secondMember, $secondAccount]];
        foreach ($owners as [$memberId]) {
            $this->insert('pa_member_role', [
                'tenant_id' => $this->tenantId, 'tenant_member_id' => $memberId,
                'role_id' => $ownerRole, 'assigned_at' => self::NOW,
            ]);
        }

        $processes = [];
        $sockets = [];
        $connectionIds = [];
        $statuses = [];
        $gate = null;
        try {
            foreach ($owners as [$memberId, $accountId]) {
                $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
                self::assertIsArray($pair);
                $processId = pcntl_fork();
                self::assertGreaterThanOrEqual(0, $processId);
                if ($processId === 0) {
                    fclose($pair[0]);
                    try {
                        $connection = $this->ownerRaceConnection();
                        $identity = $connection->prepare('SELECT CONNECTION_ID()');
                        $identity->execute();
                        $connectionId = $identity->fetchColumn();
                        fwrite($pair[1], $connectionId . "\n");
                        if (fread($pair[1], 1) !== 'g') {
                            throw new RuntimeException('Owner race start signal was missing.');
                        }
                        try {
                            ThinkPhpTestConnection::fromPdo($connection);
                            (new MemberAdminService(new AuditService()))->replaceRoles(
                                $this->tenantContext(
                                    'owner-race-' . $memberId,
                                    $this->tenantId,
                                    $memberId,
                                    $accountId,
                                ),
                                $memberId,
                                [],
                                1,
                            );
                            $outcome = 'success';
                        } catch (AdminAccessException $exception) {
                            $outcome = $exception->errorCode;
                        }
                        fwrite($pair[1], $outcome . "\n");
                        fclose($pair[1]);
                        exit(0);
                    } catch (Throwable $exception) {
                        fwrite($pair[1], 'unexpected:' . $exception::class . "\n");
                        fclose($pair[1]);
                        exit(1);
                    }
                }
                fclose($pair[1]);
                stream_set_timeout($pair[0], 15);
                $processes[] = $processId;
                $sockets[] = $pair[0];
                $connectionIds[] = (int) fgets($pair[0]);
            }

            // Open after fork so children never inherit the connection owning this row lock.
            $gate = $this->ownerRaceConnection();
            $gate->beginTransaction();
            $gate->query('SELECT id FROM pa_tenant WHERE id = ' . $this->tenantId . ' FOR UPDATE');
            foreach ($sockets as $socket) {
                self::assertSame(1, fwrite($socket, 'g'));
            }
            $waiting = $gate->prepare(<<<'SQL'
SELECT COUNT(DISTINCT thread_state.PROCESSLIST_ID)
FROM performance_schema.data_lock_waits lock_wait
JOIN performance_schema.data_locks requested
  ON requested.ENGINE_LOCK_ID = lock_wait.REQUESTING_ENGINE_LOCK_ID
JOIN performance_schema.threads thread_state
  ON thread_state.THREAD_ID = requested.THREAD_ID
WHERE requested.OBJECT_SCHEMA = ? AND requested.OBJECT_NAME = 'pa_tenant'
  AND thread_state.PROCESSLIST_ID IN (?, ?)
SQL);
            $deadline = microtime(true) + 15;
            do {
                $waiting->execute([self::DATABASE, ...$connectionIds]);
                $waitingCount = (int) $waiting->fetchColumn();
                if ($waitingCount === 2) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            self::assertSame(2, $waitingCount, 'Both owner commands must reach a real tenant row-lock wait.');
            $gate->commit();

            $outcomes = [];
            foreach ($sockets as $socket) {
                $outcomes[] = trim((string) fgets($socket));
            }
            sort($outcomes);
            self::assertSame(['LAST_ACTIVE_OWNER_REQUIRED', 'success'], $outcomes);
        } finally {
            if ($gate?->inTransaction()) {
                $gate->rollBack();
            }
            foreach ($sockets as $socket) {
                fclose($socket);
            }
            foreach ($processes as $processId) {
                pcntl_waitpid($processId, $status);
                $statuses[] = $status;
            }
            // Child exit closes inherited PDO sockets; restore the parent's fixture connections.
            $this->database = $this->ownerRaceConnection();
            $this->admin = $this->ownerRaceConnection();
        }

        foreach ($statuses as $status) {
            self::assertTrue(pcntl_wifexited($status));
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        self::assertSame(1, (int) $this->query(<<<'SQL'
SELECT COUNT(*) FROM pa_tenant_member member
JOIN pa_member_role assignment ON assignment.tenant_id = member.tenant_id
  AND assignment.tenant_member_id = member.id
JOIN pa_role role ON role.tenant_id = assignment.tenant_id AND role.id = assignment.role_id
WHERE member.status = 'active' AND role.`key` = 'core.tenant-owner'
  AND role.is_builtin = 1 AND role.status = 'active'
SQL)->fetchColumn());
    }

    /** Creates an independent native-PDO connection for the owner-lock regression. */
    private function ownerRaceConnection(): PDO
    {
        return new PDO(
            sprintf('mysql:host=127.0.0.1;port=%d;dbname=%s;charset=utf8mb4', (int) getenv('MYSQL_PORT'), self::DATABASE),
            'root',
            getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    public function testOversizedRoleCommandsFailBeforeTransactionsOrSql(): void
    {
        $service = new MemberAdminService(new AuditService());
        // Count the original array: repeated identifiers must not bypass the work bound.
        $roleIds = array_fill(0, MemberAdminService::MAX_ROLE_IDS + 1, 1);
        foreach ([
            fn() => $service->replaceRoles(
                $this->tenantContext('oversized-role-replace'),
                $this->actorMemberId,
                $roleIds,
                1,
            ),
            fn() => $service->createAdministrator(
                $this->tenantContext('oversized-admin-create'),
                'oversized@example.test',
                'Oversized',
                'Initial-password-123!',
                null,
                $roleIds,
                true,
            ),
            fn() => $service->updateAdministrator(
                $this->tenantContext('oversized-admin-update'),
                $this->actorMemberId,
                'Oversized',
                null,
                $roleIds,
                true,
                1,
            ),
        ] as $command) {
            try {
                $command();
                self::fail('Oversized role input must fail before reaching PDO.');
            } catch (AdminAccessException $exception) {
                self::assertSame('MEMBER_ROLE_LIMIT_EXCEEDED', $exception->errorCode);
                self::assertSame(422, $exception->httpStatus);
            }
        }
    }

    public function testRoleLimitAcceptsTheBoundaryAndPreservesEmptyReplacement(): void
    {
        $roleIds = [];
        for ($index = 0; $index < MemberAdminService::MAX_ROLE_IDS; ++$index) {
            $roleIds[] = $this->role($this->tenantId, 'r' . $index);
        }
        $assigned = $this->members()->replaceRoles(
            $this->tenantContext('role-limit-boundary'),
            $this->actorMemberId,
            $roleIds,
            1,
        );
        self::assertCount(MemberAdminService::MAX_ROLE_IDS, $assigned['role_keys']);

        $cleared = $this->members()->replaceRoles(
            $this->tenantContext('role-limit-empty'),
            $this->actorMemberId,
            [],
            (int) $assigned['revision'],
        );
        self::assertSame([], $cleared['role_keys']);
        self::assertSame('active', $cleared['status']);
    }

    public function testDepartmentTreeRejectsCyclesDepthOverflowAndStaleRevisions(): void
    {
        $service = new DepartmentAdminService(new AuditService());
        $parentId = null;
        $departments = [];
        for ($depth = 1; $depth <= 10; ++$depth) {
            $department = $service->create(
                $this->tenantContext('request-department-' . $depth),
                'depth-' . $depth,
                'Depth ' . $depth,
                $parentId,
                $depth,
            );
            $departments[] = $department;
            $parentId = (int) $department['id'];
        }

        $this->assertAdminError('DEPARTMENT_DEPTH_EXCEEDED', fn() => $service->create(
            $this->tenantContext('request-department-11'),
            'depth-11',
            'Depth 11',
            $parentId,
            11,
        ));
        $root = $departments[0];
        $this->assertAdminError('DEPARTMENT_CYCLE', fn() => $service->move(
            $this->tenantContext('request-department-cycle'),
            (int) $root['id'],
            (int) $departments[1]['id'],
            (int) $root['revision'],
        ));

        $updated = $service->update(
            $this->tenantContext('request-department-update'),
            (int) $root['id'],
            'root-updated',
            'Root updated',
            0,
            (int) $root['revision'],
        );
        self::assertSame('2', $updated['revision']);
        $this->assertAdminStatus(412, fn() => $service->update(
            $this->tenantContext('request-department-stale'),
            (int) $root['id'],
            'stale',
            'Stale',
            0,
            (int) $root['revision'],
        ));
    }

    public function testRolePermissionAssignmentRequiresAnAvailableTenantModule(): void
    {
        $service = new RoleAdminService(new AuditService());
        $role = $service->create(
            $this->tenantContext('request-role-create'),
            'example-reader',
            'Example reader',
            null,
        );
        $catalog = new ThinkPhpAuthorizationCatalogRepository();
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
            $this->tenantContext('request-role-permissions-disabled'),
            (int) $role['id'],
            ['example.record.read'],
            (int) $role['revision'],
        ));
        $this->database->exec("UPDATE pa_tenant_module SET status = 'enabled' WHERE tenant_id = {$this->tenantId} AND module_key = 'example.records'");
        $updated = $service->replacePermissions(
            $this->tenantContext('request-role-permissions'),
            (int) $role['id'],
            ['example.record.read'],
            (int) $role['revision'],
        );
        self::assertSame(['example.record.read'], $updated['permission_keys']);

        $this->assertAdminError('PERMISSION_NOT_ASSIGNABLE', fn() => $service->replacePermissions(
            $this->tenantContext('request-platform-permission'),
            (int) $role['id'],
            ['platform.tenant.read'],
            (int) $updated['revision'],
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
        $service = new TenantOwnerAdminService(new AuditService());
        $candidate = $service->createCandidate(
            $this->platformContext('request-owner-candidate', $operatorAccountId, $operatorId),
            $tenantId,
            'first-owner@example.test',
            'First owner',
            'Initial-password-123!',
        );
        self::assertSame('pending', $candidate['member']['status']);
        self::assertArrayNotHasKey('initial_password', $candidate);
        self::assertSame('first-owner@example.test', (string) $this->query(
            'SELECT identifier_normalized FROM pa_credential ORDER BY id DESC LIMIT 1',
        )->fetchColumn());

        $this->assertAdminError('TENANT_OWNER_CANDIDATE_EXISTS', fn() => $service->createCandidate(
            $this->platformContext('request-second-owner', $operatorAccountId, $operatorId),
            $tenantId,
            'other-owner@example.test',
            'Other owner',
            'Another-password-123!',
        ));

        $memberId = (int) $candidate['member']['id'];
        $revision = (int) $candidate['member']['revision'];
        $activated = $service->activateCandidate(
            $this->platformContext('request-owner-activate', $operatorAccountId, $operatorId),
            $tenantId,
            $memberId,
            $revision,
            'owner-activate-key',
            'Initial tenant owner confirmed',
        );
        self::assertSame('active', $activated['member']['status']);

        $replayed = $service->activateCandidate(
            $this->platformContext('request-owner-activate-retry', $operatorAccountId, $operatorId),
            $tenantId,
            $memberId,
            $revision,
            'owner-activate-key',
            'Initial tenant owner confirmed',
        );
        self::assertSame($activated['member']['revision'], $replayed['member']['revision']);
        self::assertSame(1, (int) $this->query(<<<'SQL'
SELECT COUNT(*) FROM pa_platform_audit_event WHERE event_type = 'tenant.owner-candidate.activated'
SQL)->fetchColumn());
    }

    private function members(): MemberAdminService
    {
        return new MemberAdminService(new AuditService());
    }

    private function tenantContext(
        string $requestId,
        ?int $tenantId = null,
        ?int $memberId = null,
        ?int $accountId = null,
    ): TenantContext {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            'admin-access-session',
            $tenantId ?? $this->tenantId,
            $accountId ?? $this->actorAccountId,
            $memberId ?? $this->actorMemberId,
            'admin-web',
            new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
            1,
        ), $requestId);
    }

    private function platformContext(string $requestId, int $accountId, int $operatorId): PlatformContext
    {
        return PlatformContext::fromTrustedAutomation(
            $accountId,
            $operatorId,
            'platform-web',
            $requestId,
            new DateTimeImmutable(self::NOW, new DateTimeZone('UTC')),
        );
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
                $this->tenantContext('atomic-create-invalid'),
                'atomic-new@example.test',
                'Atomic new',
                'Initial-password-123!',
                $departmentId,
                $roles,
                true,
            ));
            self::assertSame($before, $this->administrationState());
            self::assertFalse($this->database->inTransaction());
        }

        $created = $this->members()->createAdministrator(
            $this->tenantContext('atomic-create-success'),
            'atomic-new@example.test',
            'Atomic new',
            'Initial-password-123!',
            null,
            [$localRole],
            true,
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
            $this->tenantContext('atomic-edit-owner'),
            $this->actorMemberId,
            'Must roll back',
            null,
            [$ownerRole, $extraRole],
            false,
            (int) $member['revision'],
        ));
        self::assertSame($before, $this->administrationState());
        self::assertFalse($this->database->inTransaction());

        $this->assertAdminError('LAST_ACTIVE_OWNER_REQUIRED', fn() => $this->members()->updateAdministrator(
            $this->tenantContext('atomic-edit-remove-owner'),
            $this->actorMemberId,
            'Must roll back',
            null,
            [$extraRole],
            true,
            (int) $member['revision'],
        ));
        self::assertSame($before, $this->administrationState());
    }

    public function testAdministratorEditPreservesRevisionAndTenantBoundaries(): void
    {
        $role = $this->role($this->tenantId, 'atomic-role');
        $created = $this->members()->createAdministrator(
            $this->tenantContext('atomic-pending'),
            'atomic-edit@example.test',
            'Before',
            'Initial-password-123!',
            null,
            [$role],
            false,
        );
        self::assertSame('pending', $created['status']);
        $otherTenant = $this->tenant('atomic-edit-other', 'active');
        $otherRole = $this->role($otherTenant, 'foreign-role');
        $before = $this->administrationState();

        $this->assertAdminStatus(404, fn() => $this->members()->updateAdministrator(
            $this->tenantContext('atomic-edit-foreign-role'),
            (int) $created['id'],
            'Must roll back',
            null,
            [$otherRole],
            true,
            (int) $created['revision'],
        ));
        $this->assertAdminStatus(404, fn() => $this->members()->updateAdministrator(
            $this->tenantContext('atomic-edit-foreign-member', $otherTenant),
            (int) $created['id'],
            'Must roll back',
            null,
            [$otherRole],
            true,
            (int) $created['revision'],
        ));
        $this->assertAdminStatus(412, fn() => $this->members()->updateAdministrator(
            $this->tenantContext('atomic-edit-stale'),
            (int) $created['id'],
            'Must roll back',
            null,
            [$role],
            true,
            (int) $created['revision'] - 1,
        ));
        self::assertSame($before, $this->administrationState());

        $updated = $this->members()->updateAdministrator(
            $this->tenantContext('atomic-edit-success'),
            (int) $created['id'],
            'After',
            null,
            [$role],
            true,
            (int) $created['revision'],
        );
        self::assertSame('After', $updated['display_name']);
        self::assertSame('active', $updated['status']);
        self::assertSame(['atomic-role'], $updated['role_keys']);
    }

    /**
     * Captures every persisted surface owned by the aggregate, including audits.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
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
