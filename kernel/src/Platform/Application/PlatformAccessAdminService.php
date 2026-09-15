<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Platform\PlatformOperatorStatus;
use PeanutAdmin\Kernel\Persistence\Model\Account;
use PeanutAdmin\Kernel\Persistence\Model\Credential;
use PeanutAdmin\Kernel\Persistence\Model\Permission;
use PeanutAdmin\Kernel\Persistence\Model\PlatformOperator;
use PeanutAdmin\Kernel\Persistence\Model\PlatformOperatorRole;
use PeanutAdmin\Kernel\Persistence\Model\PlatformRole;
use PeanutAdmin\Kernel\Persistence\Model\PlatformRolePermission;
use PeanutAdmin\Kernel\Persistence\Model\PlatformSession;
use PeanutAdmin\Kernel\Persistence\Model\PlatformSessionToken;
use Throwable;
use think\db\Raw;
use think\facade\Db;

final readonly class PlatformAccessAdminService
{
    private const ROLE_KEY_PATTERN = '/^platform\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$/D';

    /** @var list<string> */
    private const CONTROL_PERMISSION_KEYS = [
        'platform.operator.create', 'platform.operator.update', 'platform.operator.lifecycle',
        'platform.operator.role.assign', 'platform.role.create', 'platform.role.update',
        'platform.role.archive', 'platform.role.permission.assign',
    ];

    public function __construct(
        private AuditService $audit,
        private PasswordHasher $passwords = new PasswordHasher(),
    ) {}

    /** @return array<string, mixed> */
    public function createOperator(
        PlatformContext $actor,
        string $email,
        string $displayName,
        ?string $initialPassword,
    ): array {
        try {
            $email = EmailAddress::fromString($email)->value();
        } catch (InvalidArgumentException) {
            throw AdminAccessException::invalid('EMAIL_INVALID', 'The email address is invalid.');
        }
        $displayName = $this->text($displayName, 120, 'OPERATOR_DISPLAY_NAME_INVALID');

        return $this->transaction(function () use ($actor, $email, $displayName, $initialPassword): array {
            $this->requireActor($actor);
            $credential = Credential::alias('credential')
                ->join('account account', 'account.id = credential.account_id')
                ->where('credential.identifier_type', 'email')->where('credential.identifier_normalized', $email)
                ->field([
                    'credential.account_id', 'credential.status' => 'credential_status',
                    'account.status' => 'account_status',
                ])->lock(true)->find()?->toArray();
            if ($credential === null) {
                if ($initialPassword === null || $initialPassword === '') {
                    throw AdminAccessException::invalid('INITIAL_PASSWORD_REQUIRED', 'An initial password is required for a new account.');
                }
                $accountId = $this->createAccountAndCredential($email, $displayName, $initialPassword);
            } else {
                if ($initialPassword !== null) {
                    throw AdminAccessException::invalid(
                        'INITIAL_PASSWORD_NOT_ALLOWED', 'An existing account credential cannot be overwritten.',
                    );
                }
                if ($credential['credential_status'] !== 'active' || $credential['account_status'] !== 'active') {
                    throw AdminAccessException::conflict('ACCOUNT_INACTIVE', 'The existing account and credential must be active.');
                }
                $accountId = (int) $credential['account_id'];
            }
            if (PlatformOperator::where('account_id', $accountId)->lock(true)->value('id') !== null) {
                throw AdminAccessException::conflict('PLATFORM_OPERATOR_EXISTS', 'The account is already a platform operator.');
            }
            $now = $this->now();
            $operatorId = (int) PlatformOperator::insertGetId([
                'account_id' => $accountId, 'display_name' => $displayName, 'status' => 'active',
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $operator = $this->operator($operatorId);
            $this->recordAudit(
                $actor, 'platform-operator.created', 'platform.operator.create', 'platform-operator',
                $operatorId, null, $this->operatorAuditSnapshot($operator), [],
            );

            return $operator;
        }, 'PLATFORM_OPERATOR_CONFLICT', 'The operator conflicts with an existing account or credential.');
    }

    /** @return array<string, mixed> */
    public function updateOperator(
        PlatformContext $actor,
        int $operatorId,
        int $expectedRevision,
        string $displayName,
        string $changeReason,
    ): array {
        $displayName = $this->text($displayName, 120, 'OPERATOR_DISPLAY_NAME_INVALID');
        $changeReason = $this->changeReason($changeReason);

        return Db::transaction(function () use ($actor, $operatorId, $expectedRevision, $displayName, $changeReason): array {
            $this->requireActor($actor);
            $before = $this->operator($operatorId, true);
            $this->assertOperatorRevision($before, $expectedRevision);
            if ($before['status'] === PlatformOperatorStatus::Closed->value) {
                throw AdminAccessException::conflict('PLATFORM_OPERATOR_CLOSED', 'A closed operator cannot be updated.');
            }
            if (PlatformOperator::where('id', $operatorId)->where('security_revision', $expectedRevision)->update([
                'display_name' => $displayName,
                'security_revision' => new Raw('security_revision + 1'),
                'updated_at' => $this->now(),
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $after = $this->operator($operatorId);
            $this->recordAudit(
                $actor, 'platform-operator.updated', 'platform.operator.update', 'platform-operator', $operatorId,
                $this->operatorAuditSnapshot($before), $this->operatorAuditSnapshot($after),
                ['change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @param list<int> $roleIds
     * @return array<string, mixed>
     */
    public function replaceOperatorRoles(
        PlatformContext $actor,
        int $operatorId,
        array $roleIds,
        int $expectedRevision,
        string $changeReason,
    ): array {
        $roleIds = array_values(array_unique($roleIds));
        if (count($roleIds) > 100 || array_filter($roleIds, static fn(int $id): bool => $id < 1) !== []) {
            throw AdminAccessException::invalid('ROLE_IDS_INVALID', 'Role IDs must contain at most 100 positive values.');
        }
        $changeReason = $this->changeReason($changeReason);

        return Db::transaction(function () use ($actor, $operatorId, $roleIds, $expectedRevision, $changeReason): array {
            $this->requireActor($actor);
            $this->lockControlPlane();
            $before = $this->operator($operatorId, true);
            $this->assertOperatorRevision($before, $expectedRevision);
            if ($before['status'] === PlatformOperatorStatus::Closed->value) {
                throw AdminAccessException::conflict('PLATFORM_OPERATOR_CLOSED', 'A closed operator cannot receive roles.');
            }
            $roles = $this->activeRoles($roleIds);
            if (count($roles) !== count($roleIds)) {
                throw AdminAccessException::notFound();
            }
            PlatformOperatorRole::where('platform_operator_id', $operatorId)->delete();
            $now = $this->now();
            if ($roles !== []) {
                PlatformOperatorRole::insertAll(array_map(
                    static fn(array $role): array => [
                        'platform_operator_id' => $operatorId,
                        'platform_role_id' => (int) $role['id'],
                        'assigned_by_operator_id' => $actor->operatorId,
                        'assigned_at' => $now,
                    ],
                    $roles,
                ));
            }
            if (PlatformOperator::where('id', $operatorId)->where('security_revision', $expectedRevision)->update([
                'security_revision' => new Raw('security_revision + 1'), 'updated_at' => $now,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->assertControlAdminExists();
            $after = $this->operator($operatorId);
            $this->recordAudit(
                $actor, 'platform-operator.roles-replaced', 'platform.operator.role.assign',
                'platform-operator', $operatorId, $this->operatorAuditSnapshot($before),
                $this->operatorAuditSnapshot($after), ['change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function transitionOperator(
        PlatformContext $actor,
        int $operatorId,
        int $expectedRevision,
        PlatformOperatorStatus $next,
        string $changeReason,
    ): array {
        $changeReason = $this->changeReason($changeReason);

        return Db::transaction(function () use ($actor, $operatorId, $expectedRevision, $next, $changeReason): array {
            $this->requireActor($actor);
            $this->lockControlPlane();
            $before = $this->operator($operatorId, true);
            $this->assertOperatorRevision($before, $expectedRevision);
            $current = PlatformOperatorStatus::from((string) $before['status']);
            try {
                $current->transitionTo($next);
            } catch (DomainException) {
                throw AdminAccessException::conflict(
                    'PLATFORM_OPERATOR_STATUS_INVALID',
                    "Operator cannot transition from {$current->value} to {$next->value}.",
                );
            }
            $now = $this->now();
            $changes = [
                'status' => $next->value, 'security_revision' => new Raw('security_revision + 1'), 'updated_at' => $now,
            ];
            if ($next === PlatformOperatorStatus::Suspended) {
                $changes['suspended_at'] = $now;
            } elseif ($next === PlatformOperatorStatus::Closed) {
                $changes['closed_at'] = $now;
            }
            if (PlatformOperator::where('id', $operatorId)->where('security_revision', $expectedRevision)
                ->update($changes) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            if ($next !== PlatformOperatorStatus::Active) {
                $this->revokeSessions($operatorId, $next->value, $now);
            }
            $this->assertControlAdminExists();
            $after = $this->operator($operatorId);
            $eventType = 'platform-operator.' . match ($next) {
                PlatformOperatorStatus::Active => 'activated', PlatformOperatorStatus::Suspended => 'suspended',
                PlatformOperatorStatus::Closed => 'closed',
            };
            $this->recordAudit(
                $actor, $eventType, 'platform.operator.lifecycle', 'platform-operator', $operatorId,
                $this->operatorAuditSnapshot($before), $this->operatorAuditSnapshot($after),
                ['change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function createRole(
        PlatformContext $actor,
        string $key,
        string $name,
        ?string $description,
    ): array {
        $key = trim($key);
        if (preg_match(self::ROLE_KEY_PATTERN, $key) !== 1 || strlen($key) > 96) {
            throw AdminAccessException::invalid('PLATFORM_ROLE_KEY_INVALID', 'The platform role key is invalid.');
        }
        $name = $this->text($name, 120, 'PLATFORM_ROLE_NAME_INVALID');
        $description = $this->nullableText($description, 500, 'PLATFORM_ROLE_DESCRIPTION_INVALID');

        return $this->transaction(function () use ($actor, $key, $name, $description): array {
            $this->requireActor($actor);
            $now = $this->now();
            $roleId = (int) PlatformRole::insertGetId([
                'key' => $key, 'name' => $name, 'description' => $description, 'is_builtin' => 0,
                'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $role = $this->role($roleId);
            $this->recordAudit(
                $actor, 'platform-role.created', 'platform.role.create', 'platform-role', $roleId, null, $role, [],
            );

            return $role;
        }, 'PLATFORM_ROLE_KEY_CONFLICT', 'The platform role key is already in use.');
    }

    /** @return array<string, mixed> */
    public function updateRole(
        PlatformContext $actor,
        int $roleId,
        int $expectedRevision,
        string $name,
        ?string $description,
        string $changeReason,
    ): array {
        $name = $this->text($name, 120, 'PLATFORM_ROLE_NAME_INVALID');
        $description = $this->nullableText($description, 500, 'PLATFORM_ROLE_DESCRIPTION_INVALID');
        $changeReason = $this->changeReason($changeReason);

        return Db::transaction(function () use ($actor, $roleId, $expectedRevision, $name, $description, $changeReason): array {
            $this->requireActor($actor);
            $before = $this->role($roleId, true);
            $this->assertRoleRevision($before, $expectedRevision);
            if ($before['status'] === 'archived') {
                throw AdminAccessException::conflict('PLATFORM_ROLE_ARCHIVED', 'An archived role cannot be updated.');
            }
            if (PlatformRole::where('id', $roleId)->where('revision', $expectedRevision)->update([
                'name' => $name, 'description' => $description,
                'revision' => new Raw('revision + 1'), 'updated_at' => $this->now(),
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $after = $this->role($roleId);
            $this->recordAudit(
                $actor, 'platform-role.updated', 'platform.role.update', 'platform-role', $roleId,
                $before, $after, ['change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function archiveRole(
        PlatformContext $actor,
        int $roleId,
        int $expectedRevision,
        string $changeReason,
    ): array {
        $changeReason = $this->changeReason($changeReason);

        return Db::transaction(function () use ($actor, $roleId, $expectedRevision, $changeReason): array {
            $this->requireActor($actor);
            $this->lockControlPlane();
            $before = $this->role($roleId, true);
            $this->assertRoleRevision($before, $expectedRevision);
            if ((bool) $before['is_builtin']) {
                throw AdminAccessException::conflict('BUILTIN_ROLE_IMMUTABLE', 'Built-in platform roles cannot be archived.');
            }
            if ($before['status'] === 'archived') {
                throw AdminAccessException::conflict('PLATFORM_ROLE_ARCHIVED', 'The platform role is already archived.');
            }
            $now = $this->now();
            if (PlatformRole::where('id', $roleId)->where('revision', $expectedRevision)->update([
                'status' => 'archived', 'revision' => new Raw('revision + 1'),
                'archived_at' => $now, 'updated_at' => $now,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpRoleOperators($roleId, $now);
            $this->assertControlAdminExists();
            $after = $this->role($roleId);
            $this->recordAudit(
                $actor, 'platform-role.archived', 'platform.role.archive', 'platform-role', $roleId,
                $before, $after, ['change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @param list<string> $permissionKeys
     * @return array<string, mixed>
     */
    public function replaceRolePermissions(
        PlatformContext $actor,
        int $roleId,
        array $permissionKeys,
        int $expectedRevision,
        string $changeReason,
    ): array {
        $permissionKeys = array_values(array_unique($permissionKeys));
        if (count($permissionKeys) > 200) {
            throw AdminAccessException::invalid('PERMISSION_KEYS_INVALID', 'At most 200 permissions may be assigned.');
        }
        $changeReason = $this->changeReason($changeReason);

        return Db::transaction(function () use ($actor, $roleId, $permissionKeys, $expectedRevision, $changeReason): array {
            $this->requireActor($actor);
            $this->lockControlPlane();
            $before = $this->role($roleId, true);
            $this->assertRoleRevision($before, $expectedRevision);
            if ($before['status'] !== 'active') {
                throw AdminAccessException::conflict('PLATFORM_ROLE_INACTIVE', 'Only an active role can receive permissions.');
            }
            $permissions = $this->platformPermissions($permissionKeys);
            if (count($permissions) !== count($permissionKeys)) {
                throw AdminAccessException::invalid(
                    'PERMISSION_NOT_ASSIGNABLE', 'Only active platform control-plane permissions may be assigned.',
                );
            }
            PlatformRolePermission::where('platform_role_id', $roleId)->delete();
            $now = $this->now();
            if ($permissions !== []) {
                PlatformRolePermission::insertAll(array_map(
                    static fn(array $permission): array => [
                        'platform_role_id' => $roleId,
                        'permission_id' => (int) $permission['id'],
                        'granted_at' => $now,
                    ],
                    $permissions,
                ));
            }
            if (PlatformRole::where('id', $roleId)->where('revision', $expectedRevision)->update([
                'revision' => new Raw('revision + 1'), 'updated_at' => $now,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpRoleOperators($roleId, $now);
            $this->assertControlAdminExists();
            $after = $this->role($roleId);
            $this->recordAudit(
                $actor, 'platform-role.permissions-replaced', 'platform.role.permission.assign',
                'platform-role', $roleId, $before, $after, ['change_reason' => $changeReason],
            );

            return $after;
        });
    }

    private function createAccountAndCredential(string $email, string $displayName, string $password): int
    {
        $now = $this->now();
        $accountId = (int) Account::insertGetId([
            'display_name' => $displayName, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        Credential::insert([
            'account_id' => $accountId, 'kind' => 'email_password', 'identifier_type' => 'email',
            'identifier_normalized' => $email, 'secret_hash' => $this->passwords->hash($password),
            'verified_at' => $now, 'secret_changed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $accountId;
    }

    private function requireActor(PlatformContext $actor): void
    {
        if (PlatformOperator::where('id', $actor->operatorId)->where('account_id', $actor->accountId)
            ->where('status', 'active')->lock(true)->value('id') === null) {
            throw new AdminAccessException('PLATFORM_OPERATOR_INACTIVE', 403, 'An active platform operator is required.');
        }
    }

    private function lockControlPlane(): void
    {
        PlatformOperator::where('status', 'active')->order('id')->lock(true)->column('id');
    }

    private function assertControlAdminExists(): void
    {
        $rows = PlatformOperator::alias('operator')
            ->join('platform_operator_role operator_role', 'operator_role.platform_operator_id = operator.id')
            ->join('platform_role role', "role.id = operator_role.platform_role_id AND role.status = 'active'")
            ->leftJoin('platform_role_permission role_permission', 'role_permission.platform_role_id = role.id')
            ->leftJoin('permission permission', "permission.id = role_permission.permission_id AND permission.status = 'active'")
            ->where('operator.status', 'active')
            ->field(['operator.id' => 'operator_id', 'role.key' => 'role_key', 'role.is_builtin', 'permission.key' => 'permission_key'])
            ->select()->toArray();
        $operators = [];
        foreach ($rows as $row) {
            $id = (int) $row['operator_id'];
            $operators[$id] ??= ['bootstrap' => false, 'permissions' => []];
            if ($row['role_key'] === 'platform.bootstrap-owner' && (bool) $row['is_builtin']) {
                $operators[$id]['bootstrap'] = true;
            }
            if (is_string($row['permission_key']) && in_array($row['permission_key'], self::CONTROL_PERMISSION_KEYS, true)) {
                $operators[$id]['permissions'][$row['permission_key']] = true;
            }
        }
        foreach ($operators as $operator) {
            if ($operator['bootstrap'] || count($operator['permissions']) === count(self::CONTROL_PERMISSION_KEYS)) {
                return;
            }
        }
        throw AdminAccessException::conflict(
            'PLATFORM_CONTROL_ADMIN_REQUIRED', 'At least one active platform control administrator must remain.',
        );
    }

    private function revokeSessions(int $operatorId, string $reason, string $now): void
    {
        $sessionIds = PlatformSession::where('platform_operator_id', $operatorId)->column('id');
        if ($sessionIds !== []) {
            PlatformSessionToken::whereIn('session_id', $sessionIds)->where('status', 'active')->update([
                'status' => 'revoked', 'revoked_at' => $now,
            ]);
        }
        PlatformSession::where('platform_operator_id', $operatorId)->where('status', 'active')->update([
            'status' => 'revoked', 'revoked_at' => $now, 'revoke_reason' => 'operator_' . $reason, 'updated_at' => $now,
        ]);
    }

    private function bumpRoleOperators(int $roleId, string $now): void
    {
        $operatorIds = PlatformOperatorRole::where('platform_role_id', $roleId)->column('platform_operator_id');
        if ($operatorIds !== []) {
            PlatformOperator::whereIn('id', $operatorIds)->update([
                'security_revision' => new Raw('security_revision + 1'), 'updated_at' => $now,
            ]);
        }
    }

    /** @param list<int> $roleIds
     * @return list<array<string, mixed>>
     */
    private function activeRoles(array $roleIds): array
    {
        return $roleIds === [] ? [] : array_values(PlatformRole::where('status', 'active')->whereIn('id', $roleIds)
            ->order('id')->lock(true)->field('id,key')->select()->toArray());
    }

    /** @param list<string> $permissionKeys
     * @return list<array<string, mixed>>
     */
    private function platformPermissions(array $permissionKeys): array
    {
        return $permissionKeys === [] ? [] : array_values(Permission::where('status', 'active')
            ->whereLike('key', 'platform.%')->whereIn('key', $permissionKeys)->order('key')
            ->field('id,key')->select()->toArray());
    }

    /** @return array<string, mixed> */
    private function operator(int $operatorId, bool $forUpdate = false): array
    {
        $query = PlatformOperator::where('id', $operatorId);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->field(
            'id,account_id,display_name,status,security_revision,suspended_at,closed_at,created_at,updated_at',
        )->find()?->toArray();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }
        $row['email'] = Credential::where('account_id', (int) $row['account_id'])
            ->where('identifier_type', 'email')->where('status', 'active')->value('identifier_normalized');
        $row['role_keys'] = array_map('strval', PlatformOperatorRole::alias('operator_role')
            ->join('platform_role role', 'role.id = operator_role.platform_role_id')
            ->where('operator_role.platform_operator_id', $operatorId)->where('role.status', 'active')
            ->order('role.key')->distinct(true)->column('role.key'));

        return $this->normalize($row);
    }

    /** @return array<string, mixed> */
    private function role(int $roleId, bool $forUpdate = false): array
    {
        $query = PlatformRole::where('id', $roleId);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->field('id,key,name,description,is_builtin,status,revision,archived_at,created_at,updated_at')
            ->find()?->toArray();
        if ($row === null) {
            throw AdminAccessException::notFound();
        }
        $row['permission_keys'] = array_map('strval', PlatformRolePermission::alias('role_permission')
            ->join('permission permission', 'permission.id = role_permission.permission_id')
            ->where('role_permission.platform_role_id', $roleId)->where('permission.status', 'active')
            ->order('permission.key')->distinct(true)->column('permission.key'));
        $row['is_builtin'] = (bool) $row['is_builtin'];

        return $this->normalize($row);
    }

    /** @param array<string, mixed> $operator */
    private function assertOperatorRevision(array $operator, int $expectedRevision): void
    {
        if ((int) $operator['security_revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
    }

    /** @param array<string, mixed> $role */
    private function assertRoleRevision(array $role, int $expectedRevision): void
    {
        if ((int) $role['revision'] !== $expectedRevision) {
            throw AdminAccessException::revisionMismatch();
        }
    }

    private function text(string $value, int $maxLength, string $errorCode): string
    {
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $maxLength) {
            throw AdminAccessException::invalid($errorCode, 'The supplied text value is invalid.');
        }

        return $value;
    }

    private function nullableText(?string $value, int $maxLength, string $errorCode): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if (mb_strlen($value) > $maxLength) {
            throw AdminAccessException::invalid($errorCode, 'The supplied text value is invalid.');
        }

        return $value === '' ? null : $value;
    }

    private function changeReason(string $value): string
    {
        return $this->text($value, 255, 'CHANGE_REASON_REQUIRED');
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        foreach ($row as $key => $value) {
            if ($value !== null && ($key === 'id' || str_ends_with($key, '_id')
                || str_ends_with($key, '_revision') || $key === 'revision')) {
                $row[$key] = (string) $value;
            }
        }

        return $row;
    }

    /** @param array<string, mixed> $operator
     * @return array<string, mixed>
     */
    private function operatorAuditSnapshot(array $operator): array
    {
        unset($operator['email']);

        return $operator;
    }

    /** @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed> $metadata
     */
    private function recordAudit(
        PlatformContext $actor,
        string $eventType,
        string $action,
        string $targetType,
        int $targetId,
        ?array $before,
        ?array $after,
        array $metadata,
    ): void {
        $metadata += [str_replace('-', '_', $targetType) . '_id' => (string) $targetId];
        $this->audit->platform(
            $actor->operatorId, $actor->accountId, $actor->requestId, $eventType, $action,
            $metadata, $targetType, (string) $targetId, $before, $after,
        );
    }

    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation, string $conflictCode, string $conflictMessage): mixed
    {
        try {
            return Db::transaction($operation);
        } catch (Throwable $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw AdminAccessException::conflict($conflictCode, $conflictMessage);
            }
            throw $exception;
        }
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }
}
