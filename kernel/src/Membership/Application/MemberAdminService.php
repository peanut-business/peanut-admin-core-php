<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Membership\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Persistence\Model\Account;
use PeanutAdmin\Kernel\Persistence\Model\Credential;
use PeanutAdmin\Kernel\Persistence\Model\Department;
use PeanutAdmin\Kernel\Persistence\Model\MemberRole;
use PeanutAdmin\Kernel\Persistence\Model\Role;
use PeanutAdmin\Kernel\Persistence\Model\Tenant;
use PeanutAdmin\Kernel\Persistence\Model\TenantMember;
use Throwable;
use think\db\Raw;
use think\facade\Db;

/** Owns tenant member administration and atomic profile, role and status commands. */
final readonly class MemberAdminService
{
    public const MAX_ROLE_IDS = 100;

    public function __construct(
        private AuditService $audit,
        private PasswordHasher $passwords = new PasswordHasher(),
    ) {}

    /** @param list<int> $roleIds
     * @return array<string, mixed>
     */
    public function createAdministrator(
        TenantContext $actor,
        string $email,
        string $displayName,
        ?string $initialPassword,
        ?int $primaryDepartmentId,
        array $roleIds,
        bool $enabled,
    ): array {
        $this->assertRoleIdsLimit($roleIds);

        return $this->transaction(function () use (
            $actor, $email, $displayName, $initialPassword, $primaryDepartmentId, $roleIds, $enabled,
        ): array {
            $member = $this->createPendingInTransaction($actor, $email, $displayName, $initialPassword);
            if ($primaryDepartmentId !== null) {
                $member = $this->updateInTransaction(
                    $actor,
                    (int) $member['id'],
                    $displayName,
                    $primaryDepartmentId,
                    (int) $member['revision'],
                );
            }

            return $this->configureAdministratorInTransaction($actor, $member, $roleIds, $enabled);
        });
    }

    /** @param list<int> $roleIds
     * @return array<string, mixed>
     */
    public function updateAdministrator(
        TenantContext $actor,
        int $memberId,
        string $displayName,
        ?int $primaryDepartmentId,
        array $roleIds,
        bool $enabled,
        int $expectedRevision,
    ): array {
        $this->assertRoleIdsLimit($roleIds);

        return $this->transaction(function () use (
            $actor, $memberId, $displayName, $primaryDepartmentId, $roleIds, $enabled, $expectedRevision,
        ): array {
            $this->requireTenantStatus($actor->tenantId, 'active', true);
            $member = $this->requireMember($actor->tenantId, $memberId, true);
            if ((int) $member['authorization_revision'] !== $expectedRevision) {
                throw AdminAccessException::revisionMismatch();
            }
            $member = $this->updateInTransaction(
                $actor,
                $memberId,
                $displayName,
                $primaryDepartmentId,
                $expectedRevision,
            );

            return $this->configureAdministratorInTransaction($actor, $member, $roleIds, $enabled);
        });
    }

    /** @param array<string, mixed> $member
     * @param list<int> $roleIds
     * @return array<string, mixed>
     */
    private function configureAdministratorInTransaction(
        TenantContext $actor,
        array $member,
        array $roleIds,
        bool $enabled,
    ): array {
        if ($roleIds === []) {
            throw AdminAccessException::invalid('ADMIN_ROLE_REQUIRED', 'An administrator role is required.');
        }
        $memberId = (int) $member['id'];
        $member = $this->replaceRolesInTransaction($actor, $memberId, $roleIds, (int) $member['revision']);
        if ($enabled && in_array($member['status'], ['pending', 'suspended'], true)) {
            return $this->transitionInTransaction(
                $actor,
                $memberId,
                ['pending', 'suspended'],
                'active',
                (int) $member['revision'],
                'core.member.activate',
            );
        }
        if (!$enabled && $member['status'] === 'active') {
            return $this->transitionInTransaction(
                $actor,
                $memberId,
                ['active'],
                'suspended',
                (int) $member['revision'],
                'core.member.suspend',
            );
        }

        return $member;
    }

    /** @return array{items: list<array<string, mixed>>, total: int} */
    public function list(int $tenantId, PageRequest $page): array
    {
        $query = TenantMember::where('tenant_id', $tenantId);
        $total = (int) (clone $query)->count();
        $rows = $query->field(
            'id,display_name,member_no,member_type,primary_department_id,status,security_revision,authorization_revision',
        )->order('id')->limit($page->offset(), $page->pageSize)->select()->toArray();

        return ['items' => $this->hydrateRoles($tenantId, array_values($rows)), 'total' => $total];
    }

    /** @return array<string, mixed> */
    public function get(int $tenantId, int $memberId): array
    {
        $row = TenantMember::where('tenant_id', $tenantId)->where('id', $memberId)->field(
            'id,display_name,member_no,member_type,primary_department_id,status,security_revision,authorization_revision',
        )->find()?->toArray();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }

        return $this->hydrateRoles($tenantId, [$row])[0];
    }

    /** @return array<string, mixed> */
    public function createPending(
        TenantContext $actor,
        string $email,
        string $displayName,
        ?string $initialPassword,
    ): array {
        return $this->transaction(fn(): array => $this->createPendingInTransaction(
            $actor,
            $email,
            $displayName,
            $initialPassword,
        ));
    }

    /** @return array<string, mixed> */
    private function createPendingInTransaction(
        TenantContext $actor,
        string $email,
        string $displayName,
        ?string $initialPassword,
    ): array {
        try {
            $identifier = EmailAddress::fromString($email)->value();
        } catch (InvalidArgumentException) {
            throw AdminAccessException::invalid('EMAIL_INVALID', 'The email address is invalid.');
        }
        $this->requireTenantStatus($actor->tenantId, 'active', true);
        $credential = Credential::where('identifier_type', 'email')
            ->where('identifier_normalized', $identifier)->lock(true)->field('id,account_id,status')->find()?->toArray();
        if ($credential === null) {
            if ($initialPassword === null || $initialPassword === '') {
                throw AdminAccessException::invalid(
                    'INITIAL_PASSWORD_REQUIRED',
                    'An initial password is required for a new account.',
                );
            }
            $accountId = $this->createAccountAndCredential($identifier, $displayName, $initialPassword);
        } else {
            if ($initialPassword !== null) {
                throw AdminAccessException::invalid(
                    'INITIAL_PASSWORD_NOT_ALLOWED',
                    'An existing account credential cannot be overwritten.',
                );
            }
            if ($credential['status'] !== 'active') {
                throw AdminAccessException::conflict('CREDENTIAL_INACTIVE', 'The account credential is inactive.');
            }
            $accountId = (int) $credential['account_id'];
            if (Account::where('id', $accountId)->lock(true)->value('status') !== 'active') {
                throw AdminAccessException::conflict('ACCOUNT_INACTIVE', 'The account is inactive.');
            }
        }
        $existing = TenantMember::where('tenant_id', $actor->tenantId)
            ->where('account_id', $accountId)->lock(true)->field('id,status')->find()?->toArray();
        if ($existing !== null && $existing['status'] !== 'left') {
            throw AdminAccessException::conflict('MEMBER_ALREADY_EXISTS', 'The account is already a tenant member.');
        }
        $now = $this->now();
        if ($existing === null) {
            $memberId = (int) TenantMember::insertGetId([
                'tenant_id' => $actor->tenantId,
                'account_id' => $accountId,
                'display_name' => $displayName,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $memberId = (int) $existing['id'];
            TenantMember::where('tenant_id', $actor->tenantId)->where('id', $memberId)->update([
                'display_name' => $displayName,
                'status' => 'pending',
                'primary_department_id' => null,
                'security_revision' => new Raw('security_revision + 1'),
                'authorization_revision' => new Raw('authorization_revision + 1'),
                'joined_at' => null,
                'suspended_at' => null,
                'left_at' => null,
                'updated_at' => $now,
            ]);
            MemberRole::where('tenant_id', $actor->tenantId)
                ->where('tenant_member_id', $memberId)->delete();
        }
        $this->bumpTenantAuthorization($actor->tenantId, $now);
        $this->recordAudit($actor, 'tenant.member.pending-created', 'core.member.create', $memberId);

        return $this->get($actor->tenantId, $memberId);
    }

    /** @return array<string, mixed> */
    public function update(
        TenantContext $actor,
        int $memberId,
        ?string $displayName,
        ?int $primaryDepartmentId,
        int $expectedRevision,
    ): array {
        return $this->transaction(fn(): array => $this->updateInTransaction(
            $actor,
            $memberId,
            $displayName,
            $primaryDepartmentId,
            $expectedRevision,
        ));
    }

    /** @return array<string, mixed> */
    private function updateInTransaction(
        TenantContext $actor,
        int $memberId,
        ?string $displayName,
        ?int $primaryDepartmentId,
        int $expectedRevision,
    ): array {
        $member = $this->requireMember($actor->tenantId, $memberId, true);
        if ((int) $member['authorization_revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
        if ($primaryDepartmentId !== null && Department::where('tenant_id', $actor->tenantId)
            ->where('id', $primaryDepartmentId)->where('status', 'active')->value('id') === null) {
            throw AdminAccessException::notFound();
        }
        $now = $this->now();
        if (TenantMember::where('tenant_id', $actor->tenantId)->where('id', $memberId)
            ->where('authorization_revision', $expectedRevision)->update([
                'display_name' => $displayName,
                'primary_department_id' => $primaryDepartmentId,
                'authorization_revision' => new Raw('authorization_revision + 1'),
                'updated_at' => $now,
            ]) !== 1) {
            throw AdminAccessException::revisionMismatch();
        }
        $this->bumpTenantAuthorization($actor->tenantId, $now);
        $this->recordAudit($actor, 'tenant.member.updated', 'core.member.update', $memberId);

        return $this->get($actor->tenantId, $memberId);
    }

    /** @return array<string, mixed> */
    public function activate(TenantContext $actor, int $memberId, int $expectedRevision): array
    {
        return $this->transition($actor, $memberId, ['pending', 'suspended'], 'active', $expectedRevision, 'core.member.activate');
    }

    /** @return array<string, mixed> */
    public function suspend(TenantContext $actor, int $memberId, int $expectedRevision): array
    {
        return $this->transition($actor, $memberId, ['active'], 'suspended', $expectedRevision, 'core.member.suspend');
    }

    /** @return array<string, mixed> */
    public function leave(TenantContext $actor, int $memberId, int $expectedRevision): array
    {
        return $this->transition(
            $actor,
            $memberId,
            ['pending', 'active', 'suspended'],
            'left',
            $expectedRevision,
            'core.member.leave',
        );
    }

    /** @param list<int> $roleIds
     * @return array<string, mixed>
     */
    public function replaceRoles(
        TenantContext $actor,
        int $memberId,
        array $roleIds,
        int $expectedRevision,
    ): array {
        $this->assertRoleIdsLimit($roleIds);

        return $this->transaction(fn(): array => $this->replaceRolesInTransaction(
            $actor,
            $memberId,
            $roleIds,
            $expectedRevision,
        ));
    }

    /** @param list<int> $roleIds */
    private function assertRoleIdsLimit(array $roleIds): void
    {
        if (count($roleIds) > self::MAX_ROLE_IDS) {
            throw AdminAccessException::invalid(
                'MEMBER_ROLE_LIMIT_EXCEEDED',
                'At most ' . self::MAX_ROLE_IDS . ' role identifiers may be supplied.',
            );
        }
    }

    /** @param list<int> $roleIds
     * @return array<string, mixed>
     */
    private function replaceRolesInTransaction(
        TenantContext $actor,
        int $memberId,
        array $roleIds,
        int $expectedRevision,
    ): array {
        $this->requireTenantStatus($actor->tenantId, 'active', true);
        $roleIds = array_values(array_unique($roleIds));
        $member = $this->requireMember($actor->tenantId, $memberId, true);
        if ((int) $member['authorization_revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
        $roles = $this->rolesByIds($actor->tenantId, $roleIds);
        if (count($roles) !== count($roleIds)) {
            throw AdminAccessException::notFound();
        }
        $currentlyOwner = $this->memberIsOwner($actor->tenantId, $memberId);
        $keepsOwner = false;
        foreach ($roles as $role) {
            $keepsOwner = $keepsOwner || ($role['key'] === 'core.tenant-owner' && (int) $role['is_builtin'] === 1);
        }
        if ($currentlyOwner && !$keepsOwner) {
            $this->assertNotLastActiveOwner($actor->tenantId, $memberId);
        }
        MemberRole::where('tenant_id', $actor->tenantId)->where('tenant_member_id', $memberId)->delete();
        $now = $this->now();
        if ($roleIds !== []) {
            MemberRole::insertAll(array_map(
                static fn(int $roleId): array => [
                    'tenant_id' => $actor->tenantId,
                    'tenant_member_id' => $memberId,
                    'role_id' => $roleId,
                    'assigned_by_member_id' => $actor->memberId,
                    'assigned_at' => $now,
                ],
                $roleIds,
            ));
        }
        if (TenantMember::where('tenant_id', $actor->tenantId)->where('id', $memberId)
            ->where('authorization_revision', $expectedRevision)->update([
                'authorization_revision' => new Raw('authorization_revision + 1'),
                'updated_at' => $now,
            ]) !== 1) {
            throw AdminAccessException::revisionMismatch();
        }
        $this->bumpTenantAuthorization($actor->tenantId, $now);
        $this->recordAudit($actor, 'tenant.member.roles-replaced', 'core.member.role.assign', $memberId);

        return $this->get($actor->tenantId, $memberId);
    }

    /** @param list<string> $fromStatuses
     * @return array<string, mixed>
     */
    private function transition(
        TenantContext $actor,
        int $memberId,
        array $fromStatuses,
        string $nextStatus,
        int $expectedRevision,
        string $action,
    ): array {
        return $this->transaction(fn(): array => $this->transitionInTransaction(
            $actor,
            $memberId,
            $fromStatuses,
            $nextStatus,
            $expectedRevision,
            $action,
        ));
    }

    /** @param list<string> $fromStatuses
     * @return array<string, mixed>
     */
    private function transitionInTransaction(
        TenantContext $actor,
        int $memberId,
        array $fromStatuses,
        string $nextStatus,
        int $expectedRevision,
        string $action,
    ): array {
        $this->requireTenantStatus($actor->tenantId, 'active', true);
        $member = $this->requireMember($actor->tenantId, $memberId, true);
        if ((int) $member['authorization_revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
        if (!in_array($member['status'], $fromStatuses, true)) {
            throw AdminAccessException::conflict('MEMBER_STATUS_CONFLICT', 'The member status transition is not allowed.');
        }
        if (in_array($nextStatus, ['suspended', 'left'], true) && $this->memberIsOwner($actor->tenantId, $memberId)) {
            $this->assertNotLastActiveOwner($actor->tenantId, $memberId);
        }
        $now = $this->now();
        $data = [
            'status' => $nextStatus,
            'security_revision' => new Raw('security_revision + 1'),
            'authorization_revision' => new Raw('authorization_revision + 1'),
            'updated_at' => $now,
        ];
        if ($nextStatus === 'active') {
            $data['joined_at'] = $now;
        } elseif ($nextStatus === 'suspended') {
            $data['suspended_at'] = $now;
        } elseif ($nextStatus === 'left') {
            $data['left_at'] = $now;
        }
        if (TenantMember::where('tenant_id', $actor->tenantId)->where('id', $memberId)
            ->where('authorization_revision', $expectedRevision)->update($data) !== 1) {
            throw AdminAccessException::revisionMismatch();
        }
        $this->bumpTenantAuthorization($actor->tenantId, $now);
        $this->recordAudit($actor, 'tenant.member.' . $nextStatus, $action, $memberId);

        return $this->get($actor->tenantId, $memberId);
    }

    private function createAccountAndCredential(string $identifier, string $displayName, string $password): int
    {
        $now = $this->now();
        $accountId = (int) Account::insertGetId([
            'display_name' => $displayName,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Credential::insert([
            'account_id' => $accountId,
            'kind' => 'email_password',
            'identifier_type' => 'email',
            'identifier_normalized' => $identifier,
            'secret_hash' => $this->passwords->hash($password),
            'verified_at' => $now,
            'secret_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $accountId;
    }

    /** @return array<string, mixed> */
    private function requireMember(int $tenantId, int $memberId, bool $forUpdate): array
    {
        $query = TenantMember::where('tenant_id', $tenantId)->where('id', $memberId);
        if ($forUpdate) {
            $query->lock(true);
        }

        return $query->find()?->toArray() ?? throw AdminAccessException::notFound();
    }

    private function requireTenantStatus(int $tenantId, string $status, bool $forUpdate): void
    {
        $query = Tenant::where('id', $tenantId);
        if ($forUpdate) {
            $query->lock(true);
        }
        if ($query->value('status') !== $status) {
            throw new AdminAccessException('TENANT_STATUS_INVALID', 403, 'The tenant status does not allow this operation.');
        }
    }

    private function memberIsOwner(int $tenantId, int $memberId): bool
    {
        return MemberRole::alias('member_role')
            ->join('role role', 'role.tenant_id = member_role.tenant_id AND role.id = member_role.role_id')
            ->where('member_role.tenant_id', $tenantId)
            ->where('member_role.tenant_member_id', $memberId)
            ->where('role.key', 'core.tenant-owner')
            ->where('role.is_builtin', 1)
            ->where('role.status', 'active')
            ->value('member_role.id') !== null;
    }

    private function assertNotLastActiveOwner(int $tenantId, int $memberId): void
    {
        $otherOwners = TenantMember::alias('member')
            ->join(
                'member_role member_role',
                'member_role.tenant_id = member.tenant_id AND member_role.tenant_member_id = member.id',
            )
            ->join('role role', 'role.tenant_id = member_role.tenant_id AND role.id = member_role.role_id')
            ->where('member.tenant_id', $tenantId)
            ->where('member.status', 'active')
            ->where('member.id', '<>', $memberId)
            ->where('role.key', 'core.tenant-owner')
            ->where('role.is_builtin', 1)
            ->where('role.status', 'active')
            ->distinct(true)
            ->count('member.id');
        if ((int) $otherOwners === 0) {
            throw AdminAccessException::conflict(
                'LAST_ACTIVE_OWNER_REQUIRED',
                'The final active tenant owner cannot be removed or suspended.',
            );
        }
    }

    /** @param list<int> $roleIds
     * @return list<array{key: string, is_builtin: int}>
     */
    private function rolesByIds(int $tenantId, array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        /** @var list<array{key: string, is_builtin: int}> $roles */
        $roles = Role::where('tenant_id', $tenantId)->where('status', 'active')
            ->whereIn('id', $roleIds)->field('id,key,is_builtin')->select()->toArray();

        return $roles;
    }

    /** @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function hydrateRoles(int $tenantId, array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $memberIds = array_map('intval', array_column($rows, 'id'));
        $roleKeys = [];
        foreach (MemberRole::alias('member_role')
            ->join('role role', 'role.tenant_id = member_role.tenant_id AND role.id = member_role.role_id')
            ->where('member_role.tenant_id', $tenantId)->whereIn('member_role.tenant_member_id', $memberIds)
            ->field(['member_role.tenant_member_id', 'role.key'])->order('role.key')->select()->toArray() as $role) {
            $roleKeys[(int) $role['tenant_member_id']][] = (string) $role['key'];
        }
        foreach ($rows as &$row) {
            $memberId = (int) $row['id'];
            $row = [
                'id' => (string) $row['id'],
                'display_name' => $row['display_name'],
                'member_no' => $row['member_no'],
                'member_type' => $row['member_type'],
                'primary_department_id' => $row['primary_department_id'] === null
                    ? null
                    : (string) $row['primary_department_id'],
                'status' => $row['status'],
                'security_revision' => (string) $row['security_revision'],
                'revision' => (string) $row['authorization_revision'],
                'role_keys' => array_values(array_unique($roleKeys[$memberId] ?? [])),
            ];
        }
        unset($row);

        return $rows;
    }

    private function bumpTenantAuthorization(int $tenantId, string $now): void
    {
        Tenant::where('id', $tenantId)->update([
            'authorization_revision' => new Raw('authorization_revision + 1'),
            'updated_at' => $now,
        ]);
    }

    private function recordAudit(TenantContext $actor, string $eventType, string $action, int $memberId): void
    {
        $this->audit->tenantMember(
            context: $actor,
            eventType: $eventType,
            action: $action,
            targetResourceType: 'member',
            targetResourceId: (string) $memberId,
        );
    }

    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        try {
            return Db::transaction($operation);
        } catch (Throwable $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw AdminAccessException::conflict('RELATION_CONFLICT', 'The requested relation already exists.');
            }

            throw $exception;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
