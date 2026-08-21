<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Application;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use JsonException;
use PDO;
use PDOException;
use PDOStatement;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Platform\PlatformOperatorStatus;
use Throwable;

final readonly class PlatformAccessAdminService
{
    private const ROLE_KEY_PATTERN = '/^platform\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*(?:\.[a-z][a-z0-9]*(?:-[a-z0-9]+)*)*$/D';

    /** @var list<string> */
    private const CONTROL_PERMISSION_KEYS = [
        'platform.operator.create',
        'platform.operator.update',
        'platform.operator.lifecycle',
        'platform.operator.role.assign',
        'platform.role.create',
        'platform.role.update',
        'platform.role.archive',
        'platform.role.permission.assign',
    ];

    public function __construct(
        private PDO $pdo,
        private PasswordHasher $passwords = new PasswordHasher(),
    ) {}

    /** @return array<string, mixed> */
    public function createOperator(
        int $actorOperatorId,
        int $actorAccountId,
        string $email,
        string $displayName,
        ?string $initialPassword,
        string $requestId,
    ): array {
        try {
            $email = EmailAddress::fromString($email)->value();
        } catch (InvalidArgumentException) {
            throw AdminAccessException::invalid('EMAIL_INVALID', 'The email address is invalid.');
        }
        $displayName = $this->text($displayName, 120, 'OPERATOR_DISPLAY_NAME_INVALID');

        try {
            return $this->transaction(function () use (
                $actorOperatorId,
                $actorAccountId,
                $email,
                $displayName,
                $initialPassword,
                $requestId,
            ): array {
                $this->requireActor($actorOperatorId, $actorAccountId);
                $credential = $this->fetchOne(<<<'SQL'
SELECT c.account_id, c.status AS credential_status, a.status AS account_status
FROM pa_credential c
JOIN pa_account a ON a.id = c.account_id
WHERE c.identifier_type = 'email' AND c.identifier_normalized = :email
FOR UPDATE
SQL, ['email' => $email]);
                if ($credential === null) {
                    if ($initialPassword === null || $initialPassword === '') {
                        throw AdminAccessException::invalid(
                            'INITIAL_PASSWORD_REQUIRED',
                            'An initial password is required for a new account.',
                        );
                    }
                    $accountId = $this->createAccountAndCredential($email, $displayName, $initialPassword);
                } else {
                    if ($initialPassword !== null) {
                        throw AdminAccessException::invalid(
                            'INITIAL_PASSWORD_NOT_ALLOWED',
                            'An existing account credential cannot be overwritten.',
                        );
                    }
                    if ($credential['credential_status'] !== 'active' || $credential['account_status'] !== 'active') {
                        throw AdminAccessException::conflict(
                            'ACCOUNT_INACTIVE',
                            'The existing account and credential must be active.',
                        );
                    }
                    $accountId = (int) $credential['account_id'];
                }
                if ($this->fetchOne(
                    'SELECT id FROM pa_platform_operator WHERE account_id = :account_id FOR UPDATE',
                    ['account_id' => $accountId],
                ) !== null) {
                    throw AdminAccessException::conflict(
                        'PLATFORM_OPERATOR_EXISTS',
                        'The account is already a platform operator.',
                    );
                }

                $now = $this->now();
                $this->execute(<<<'SQL'
INSERT INTO pa_platform_operator (account_id, display_name, status, created_at, updated_at)
VALUES (:account_id, :display_name, 'active', :created_at, :updated_at)
SQL, [
                    'account_id' => $accountId,
                    'display_name' => $displayName,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $operatorId = (int) $this->pdo->lastInsertId();
                $operator = $this->operator($operatorId);
                $this->audit(
                    $actorOperatorId,
                    $actorAccountId,
                    'platform-operator.created',
                    'platform.operator.create',
                    'platform-operator',
                    (string) $operatorId,
                    $requestId,
                    null,
                    $this->operatorAuditSnapshot($operator),
                    ['operator_id' => (string) $operatorId],
                );

                return $operator;
            });
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw AdminAccessException::conflict(
                    'PLATFORM_OPERATOR_CONFLICT',
                    'The operator conflicts with an existing account or credential.',
                );
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function updateOperator(
        int $actorOperatorId,
        int $actorAccountId,
        int $operatorId,
        int $expectedRevision,
        string $displayName,
        string $changeReason,
        string $requestId,
    ): array {
        $displayName = $this->text($displayName, 120, 'OPERATOR_DISPLAY_NAME_INVALID');
        $changeReason = $this->changeReason($changeReason);

        return $this->transaction(function () use (
            $actorOperatorId,
            $actorAccountId,
            $operatorId,
            $expectedRevision,
            $displayName,
            $changeReason,
            $requestId,
        ): array {
            $this->requireActor($actorOperatorId, $actorAccountId);
            $before = $this->operator($operatorId, true);
            $this->assertOperatorRevision($before, $expectedRevision);
            if ($before['status'] === PlatformOperatorStatus::Closed->value) {
                throw AdminAccessException::conflict('PLATFORM_OPERATOR_CLOSED', 'A closed operator cannot be updated.');
            }
            if ($this->execute(<<<'SQL'
UPDATE pa_platform_operator
SET display_name = :display_name, security_revision = security_revision + 1, updated_at = :updated_at
WHERE id = :operator_id AND security_revision = :expected_revision
SQL, [
                'display_name' => $displayName,
                'updated_at' => $this->now(),
                'operator_id' => $operatorId,
                'expected_revision' => $expectedRevision,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $after = $this->operator($operatorId);
            $this->audit(
                $actorOperatorId,
                $actorAccountId,
                'platform-operator.updated',
                'platform.operator.update',
                'platform-operator',
                (string) $operatorId,
                $requestId,
                $this->operatorAuditSnapshot($before),
                $this->operatorAuditSnapshot($after),
                ['operator_id' => (string) $operatorId, 'change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @param list<int> $roleIds
     * @return array<string, mixed>
     */
    public function replaceOperatorRoles(
        int $actorOperatorId,
        int $actorAccountId,
        int $operatorId,
        array $roleIds,
        int $expectedRevision,
        string $changeReason,
        string $requestId,
    ): array {
        $roleIds = array_values(array_unique($roleIds));
        if (count($roleIds) > 100 || array_filter($roleIds, static fn(int $id): bool => $id < 1) !== []) {
            throw AdminAccessException::invalid('ROLE_IDS_INVALID', 'Role IDs must contain at most 100 positive values.');
        }
        $changeReason = $this->changeReason($changeReason);

        return $this->transaction(function () use (
            $actorOperatorId,
            $actorAccountId,
            $operatorId,
            $roleIds,
            $expectedRevision,
            $changeReason,
            $requestId,
        ): array {
            $this->requireActor($actorOperatorId, $actorAccountId);
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
            $this->execute(
                'DELETE FROM pa_platform_operator_role WHERE platform_operator_id = :operator_id',
                ['operator_id' => $operatorId],
            );
            $now = $this->now();
            foreach ($roles as $role) {
                $this->execute(<<<'SQL'
INSERT INTO pa_platform_operator_role (
    platform_operator_id, platform_role_id, assigned_by_operator_id, assigned_at
) VALUES (:operator_id, :role_id, :assigner_id, :assigned_at)
SQL, [
                    'operator_id' => $operatorId,
                    'role_id' => (int) $role['id'],
                    'assigner_id' => $actorOperatorId,
                    'assigned_at' => $now,
                ]);
            }
            if ($this->execute(<<<'SQL'
UPDATE pa_platform_operator
SET security_revision = security_revision + 1, updated_at = :updated_at
WHERE id = :operator_id AND security_revision = :expected_revision
SQL, [
                'updated_at' => $now,
                'operator_id' => $operatorId,
                'expected_revision' => $expectedRevision,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->assertControlAdminExists();
            $after = $this->operator($operatorId);
            $this->audit(
                $actorOperatorId,
                $actorAccountId,
                'platform-operator.roles-replaced',
                'platform.operator.role.assign',
                'platform-operator',
                (string) $operatorId,
                $requestId,
                $this->operatorAuditSnapshot($before),
                $this->operatorAuditSnapshot($after),
                ['operator_id' => (string) $operatorId, 'change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function transitionOperator(
        int $actorOperatorId,
        int $actorAccountId,
        int $operatorId,
        int $expectedRevision,
        PlatformOperatorStatus $next,
        string $changeReason,
        string $requestId,
    ): array {
        $changeReason = $this->changeReason($changeReason);

        return $this->transaction(function () use (
            $actorOperatorId,
            $actorAccountId,
            $operatorId,
            $expectedRevision,
            $next,
            $changeReason,
            $requestId,
        ): array {
            $this->requireActor($actorOperatorId, $actorAccountId);
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
            if ($this->execute(<<<'SQL'
UPDATE pa_platform_operator
SET status = :status, security_revision = security_revision + 1,
    suspended_at = CASE WHEN :suspension_status = 'suspended' THEN :suspended_at ELSE suspended_at END,
    closed_at = CASE WHEN :closed_status = 'closed' THEN :closed_at ELSE closed_at END,
    updated_at = :updated_at
WHERE id = :operator_id AND security_revision = :expected_revision
SQL, [
                'status' => $next->value,
                'suspension_status' => $next->value,
                'closed_status' => $next->value,
                'suspended_at' => $now,
                'closed_at' => $now,
                'updated_at' => $now,
                'operator_id' => $operatorId,
                'expected_revision' => $expectedRevision,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            if ($next !== PlatformOperatorStatus::Active) {
                $this->revokeSessions($operatorId, $next->value, $now);
            }
            $this->assertControlAdminExists();
            $after = $this->operator($operatorId);
            $eventType = 'platform-operator.' . match ($next) {
                PlatformOperatorStatus::Active => 'activated',
                PlatformOperatorStatus::Suspended => 'suspended',
                PlatformOperatorStatus::Closed => 'closed',
            };
            $this->audit(
                $actorOperatorId,
                $actorAccountId,
                $eventType,
                'platform.operator.lifecycle',
                'platform-operator',
                (string) $operatorId,
                $requestId,
                $this->operatorAuditSnapshot($before),
                $this->operatorAuditSnapshot($after),
                ['operator_id' => (string) $operatorId, 'change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function createRole(
        int $actorOperatorId,
        int $actorAccountId,
        string $key,
        string $name,
        ?string $description,
        string $requestId,
    ): array {
        $key = trim($key);
        if (preg_match(self::ROLE_KEY_PATTERN, $key) !== 1 || strlen($key) > 96) {
            throw AdminAccessException::invalid('PLATFORM_ROLE_KEY_INVALID', 'The platform role key is invalid.');
        }
        $name = $this->text($name, 120, 'PLATFORM_ROLE_NAME_INVALID');
        $description = $this->nullableText($description, 500, 'PLATFORM_ROLE_DESCRIPTION_INVALID');

        try {
            return $this->transaction(function () use (
                $actorOperatorId,
                $actorAccountId,
                $key,
                $name,
                $description,
                $requestId,
            ): array {
                $this->requireActor($actorOperatorId, $actorAccountId);
                $now = $this->now();
                $this->execute(<<<'SQL'
INSERT INTO pa_platform_role (`key`, name, description, is_builtin, status, created_at, updated_at)
VALUES (:role_key, :name, :description, 0, 'active', :created_at, :updated_at)
SQL, [
                    'role_key' => $key,
                    'name' => $name,
                    'description' => $description,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $roleId = (int) $this->pdo->lastInsertId();
                $role = $this->role($roleId);
                $this->audit(
                    $actorOperatorId,
                    $actorAccountId,
                    'platform-role.created',
                    'platform.role.create',
                    'platform-role',
                    (string) $roleId,
                    $requestId,
                    null,
                    $role,
                    ['role_id' => (string) $roleId],
                );

                return $role;
            });
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw AdminAccessException::conflict('PLATFORM_ROLE_KEY_CONFLICT', 'The platform role key is already in use.');
            }

            throw $exception;
        }
    }

    /** @return array<string, mixed> */
    public function updateRole(
        int $actorOperatorId,
        int $actorAccountId,
        int $roleId,
        int $expectedRevision,
        string $name,
        ?string $description,
        string $changeReason,
        string $requestId,
    ): array {
        $name = $this->text($name, 120, 'PLATFORM_ROLE_NAME_INVALID');
        $description = $this->nullableText($description, 500, 'PLATFORM_ROLE_DESCRIPTION_INVALID');
        $changeReason = $this->changeReason($changeReason);

        return $this->transaction(function () use (
            $actorOperatorId,
            $actorAccountId,
            $roleId,
            $expectedRevision,
            $name,
            $description,
            $changeReason,
            $requestId,
        ): array {
            $this->requireActor($actorOperatorId, $actorAccountId);
            $before = $this->role($roleId, true);
            $this->assertRoleRevision($before, $expectedRevision);
            if ($before['status'] === 'archived') {
                throw AdminAccessException::conflict('PLATFORM_ROLE_ARCHIVED', 'An archived role cannot be updated.');
            }
            if ($this->execute(<<<'SQL'
UPDATE pa_platform_role
SET name = :name, description = :description, revision = revision + 1, updated_at = :updated_at
WHERE id = :role_id AND revision = :expected_revision
SQL, [
                'name' => $name,
                'description' => $description,
                'updated_at' => $this->now(),
                'role_id' => $roleId,
                'expected_revision' => $expectedRevision,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $after = $this->role($roleId);
            $this->audit(
                $actorOperatorId,
                $actorAccountId,
                'platform-role.updated',
                'platform.role.update',
                'platform-role',
                (string) $roleId,
                $requestId,
                $before,
                $after,
                ['role_id' => (string) $roleId, 'change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @return array<string, mixed> */
    public function archiveRole(
        int $actorOperatorId,
        int $actorAccountId,
        int $roleId,
        int $expectedRevision,
        string $changeReason,
        string $requestId,
    ): array {
        $changeReason = $this->changeReason($changeReason);

        return $this->transaction(function () use (
            $actorOperatorId,
            $actorAccountId,
            $roleId,
            $expectedRevision,
            $changeReason,
            $requestId,
        ): array {
            $this->requireActor($actorOperatorId, $actorAccountId);
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
            if ($this->execute(<<<'SQL'
UPDATE pa_platform_role
SET status = 'archived', revision = revision + 1, archived_at = :archived_at, updated_at = :updated_at
WHERE id = :role_id AND revision = :expected_revision
SQL, [
                'archived_at' => $now,
                'updated_at' => $now,
                'role_id' => $roleId,
                'expected_revision' => $expectedRevision,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpRoleOperators($roleId, $now);
            $this->assertControlAdminExists();
            $after = $this->role($roleId);
            $this->audit(
                $actorOperatorId,
                $actorAccountId,
                'platform-role.archived',
                'platform.role.archive',
                'platform-role',
                (string) $roleId,
                $requestId,
                $before,
                $after,
                ['role_id' => (string) $roleId, 'change_reason' => $changeReason],
            );

            return $after;
        });
    }

    /** @param list<string> $permissionKeys
     * @return array<string, mixed>
     */
    public function replaceRolePermissions(
        int $actorOperatorId,
        int $actorAccountId,
        int $roleId,
        array $permissionKeys,
        int $expectedRevision,
        string $changeReason,
        string $requestId,
    ): array {
        $permissionKeys = array_values(array_unique($permissionKeys));
        if (count($permissionKeys) > 200) {
            throw AdminAccessException::invalid('PERMISSION_KEYS_INVALID', 'At most 200 permissions may be assigned.');
        }
        $changeReason = $this->changeReason($changeReason);

        return $this->transaction(function () use (
            $actorOperatorId,
            $actorAccountId,
            $roleId,
            $permissionKeys,
            $expectedRevision,
            $changeReason,
            $requestId,
        ): array {
            $this->requireActor($actorOperatorId, $actorAccountId);
            $this->lockControlPlane();
            $before = $this->role($roleId, true);
            $this->assertRoleRevision($before, $expectedRevision);
            if ($before['status'] !== 'active') {
                throw AdminAccessException::conflict('PLATFORM_ROLE_INACTIVE', 'Only an active role can receive permissions.');
            }
            $permissions = $this->platformPermissions($permissionKeys);
            if (count($permissions) !== count($permissionKeys)) {
                throw AdminAccessException::invalid(
                    'PERMISSION_NOT_ASSIGNABLE',
                    'Only active platform control-plane permissions may be assigned.',
                );
            }
            $this->execute(
                'DELETE FROM pa_platform_role_permission WHERE platform_role_id = :role_id',
                ['role_id' => $roleId],
            );
            $now = $this->now();
            foreach ($permissions as $permission) {
                $this->execute(<<<'SQL'
INSERT INTO pa_platform_role_permission (platform_role_id, permission_id, granted_at)
VALUES (:role_id, :permission_id, :granted_at)
SQL, [
                    'role_id' => $roleId,
                    'permission_id' => (int) $permission['id'],
                    'granted_at' => $now,
                ]);
            }
            if ($this->execute(<<<'SQL'
UPDATE pa_platform_role
SET revision = revision + 1, updated_at = :updated_at
WHERE id = :role_id AND revision = :expected_revision
SQL, [
                'updated_at' => $now,
                'role_id' => $roleId,
                'expected_revision' => $expectedRevision,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->bumpRoleOperators($roleId, $now);
            $this->assertControlAdminExists();
            $after = $this->role($roleId);
            $this->audit(
                $actorOperatorId,
                $actorAccountId,
                'platform-role.permissions-replaced',
                'platform.role.permission.assign',
                'platform-role',
                (string) $roleId,
                $requestId,
                $before,
                $after,
                ['role_id' => (string) $roleId, 'change_reason' => $changeReason],
            );

            return $after;
        });
    }

    private function createAccountAndCredential(string $email, string $displayName, string $password): int
    {
        $now = $this->now();
        $this->execute(<<<'SQL'
INSERT INTO pa_account (display_name, status, created_at, updated_at)
VALUES (:display_name, 'active', :created_at, :updated_at)
SQL, ['display_name' => $displayName, 'created_at' => $now, 'updated_at' => $now]);
        $accountId = (int) $this->pdo->lastInsertId();
        $this->execute(<<<'SQL'
INSERT INTO pa_credential (
    account_id, kind, identifier_type, identifier_normalized, secret_hash,
    verified_at, secret_changed_at, created_at, updated_at
) VALUES (
    :account_id, 'email_password', 'email', :email, :secret_hash,
    :verified_at, :secret_changed_at, :created_at, :updated_at
)
SQL, [
            'account_id' => $accountId,
            'email' => $email,
            'secret_hash' => $this->passwords->hash($password),
            'verified_at' => $now,
            'secret_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $accountId;
    }

    private function requireActor(int $operatorId, int $accountId): void
    {
        if ($this->fetchOne(<<<'SQL'
SELECT id FROM pa_platform_operator
WHERE id = :operator_id AND account_id = :account_id AND status = 'active'
FOR UPDATE
SQL, ['operator_id' => $operatorId, 'account_id' => $accountId]) === null) {
            throw new AdminAccessException('PLATFORM_OPERATOR_INACTIVE', 403, 'An active platform operator is required.');
        }
    }

    private function lockControlPlane(): void
    {
        $statement = $this->statement(
            "SELECT id FROM pa_platform_operator WHERE status = 'active' ORDER BY id FOR UPDATE",
        );
        $statement->execute();
        $statement->fetchAll(PDO::FETCH_COLUMN);
    }

    private function assertControlAdminExists(): void
    {
        $placeholders = implode(', ', array_fill(0, count(self::CONTROL_PERMISSION_KEYS), '?'));
        $statement = $this->statement(<<<SQL
SELECT COUNT(*) FROM (
    SELECT po.id
    FROM pa_platform_operator po
    JOIN pa_platform_operator_role por ON por.platform_operator_id = po.id
    JOIN pa_platform_role pr ON pr.id = por.platform_role_id AND pr.status = 'active'
    LEFT JOIN pa_platform_role_permission prp ON prp.platform_role_id = pr.id
    LEFT JOIN pa_permission p
      ON p.id = prp.permission_id
     AND p.status = 'active'
     AND p.`key` IN ({$placeholders})
    WHERE po.status = 'active'
    GROUP BY po.id
    HAVING MAX(CASE WHEN pr.`key` = 'platform.bootstrap-owner' AND pr.is_builtin = 1 THEN 1 ELSE 0 END) = 1
        OR COUNT(DISTINCT p.`key`) = ?
) AS control_admins
SQL);
        $parameters = [...self::CONTROL_PERMISSION_KEYS, count(self::CONTROL_PERMISSION_KEYS)];
        $statement->execute($parameters);
        if ((int) $statement->fetchColumn() < 1) {
            throw AdminAccessException::conflict(
                'PLATFORM_CONTROL_ADMIN_REQUIRED',
                'At least one active platform control administrator must remain.',
            );
        }
    }

    private function revokeSessions(int $operatorId, string $reason, string $now): void
    {
        $this->execute(<<<'SQL'
UPDATE pa_platform_session_token st
JOIN pa_platform_session s ON s.id = st.session_id
SET st.status = 'revoked', st.revoked_at = :revoked_at
WHERE s.platform_operator_id = :operator_id AND st.status = 'active'
SQL, ['revoked_at' => $now, 'operator_id' => $operatorId]);
        $this->execute(<<<'SQL'
UPDATE pa_platform_session
SET status = 'revoked', revoked_at = :revoked_at, revoke_reason = :reason, updated_at = :updated_at
WHERE platform_operator_id = :operator_id AND status = 'active'
SQL, [
            'revoked_at' => $now,
            'reason' => 'operator_' . $reason,
            'updated_at' => $now,
            'operator_id' => $operatorId,
        ]);
    }

    private function bumpRoleOperators(int $roleId, string $now): void
    {
        $this->execute(<<<'SQL'
UPDATE pa_platform_operator po
JOIN pa_platform_operator_role por ON por.platform_operator_id = po.id
SET po.security_revision = po.security_revision + 1, po.updated_at = :updated_at
WHERE por.platform_role_id = :role_id
SQL, ['updated_at' => $now, 'role_id' => $roleId]);
    }

    /** @param list<int> $roleIds
     * @return list<array<string, mixed>>
     */
    private function activeRoles(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($roleIds), '?'));
        $statement = $this->statement(
            "SELECT id, `key` FROM pa_platform_role WHERE status = 'active' AND id IN ({$placeholders}) ORDER BY id FOR UPDATE",
        );
        $statement->execute($roleIds);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param list<string> $permissionKeys
     * @return list<array<string, mixed>>
     */
    private function platformPermissions(array $permissionKeys): array
    {
        if ($permissionKeys === []) {
            return [];
        }
        $placeholders = implode(', ', array_fill(0, count($permissionKeys), '?'));
        $statement = $this->statement(<<<SQL
SELECT id, `key` FROM pa_permission
WHERE status = 'active' AND `key` LIKE 'platform.%' AND `key` IN ({$placeholders})
ORDER BY `key`
SQL);
        $statement->execute($permissionKeys);

        return array_values($statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed> */
    private function operator(int $operatorId, bool $forUpdate = false): array
    {
        $row = $this->fetchOne(<<<SQL
SELECT po.id, po.account_id, po.display_name, po.status, po.security_revision,
       po.suspended_at, po.closed_at, po.created_at, po.updated_at,
       MAX(CASE WHEN c.identifier_type = 'email' AND c.status = 'active'
           THEN c.identifier_normalized END) AS email,
       GROUP_CONCAT(DISTINCT CASE WHEN pr.status = 'active' THEN pr.`key` END
           ORDER BY pr.`key` SEPARATOR ',') AS role_keys_csv
FROM pa_platform_operator po
JOIN pa_account a ON a.id = po.account_id
LEFT JOIN pa_credential c ON c.account_id = a.id
LEFT JOIN pa_platform_operator_role por ON por.platform_operator_id = po.id
LEFT JOIN pa_platform_role pr ON pr.id = por.platform_role_id
WHERE po.id = :operator_id
GROUP BY po.id, po.account_id, po.display_name, po.status, po.security_revision,
         po.suspended_at, po.closed_at, po.created_at, po.updated_at
SQL . ($forUpdate ? ' FOR UPDATE' : ''), ['operator_id' => $operatorId]);
        if ($row === null) {
            throw AdminAccessException::notFound();
        }
        $row['role_keys'] = is_string($row['role_keys_csv']) && $row['role_keys_csv'] !== ''
            ? explode(',', $row['role_keys_csv'])
            : [];
        unset($row['role_keys_csv']);

        return $this->normalize($row);
    }

    /** @return array<string, mixed> */
    private function role(int $roleId, bool $forUpdate = false): array
    {
        $row = $this->fetchOne(<<<SQL
SELECT pr.id, pr.`key`, pr.name, pr.description, pr.is_builtin, pr.status,
       pr.revision, pr.archived_at, pr.created_at, pr.updated_at,
       GROUP_CONCAT(DISTINCT CASE WHEN p.status = 'active' THEN p.`key` END
           ORDER BY p.`key` SEPARATOR ',') AS permission_keys_csv
FROM pa_platform_role pr
LEFT JOIN pa_platform_role_permission prp ON prp.platform_role_id = pr.id
LEFT JOIN pa_permission p ON p.id = prp.permission_id
WHERE pr.id = :role_id
GROUP BY pr.id, pr.`key`, pr.name, pr.description, pr.is_builtin, pr.status,
         pr.revision, pr.archived_at, pr.created_at, pr.updated_at
SQL . ($forUpdate ? ' FOR UPDATE' : ''), ['role_id' => $roleId]);
        if ($row === null) {
            throw AdminAccessException::notFound();
        }
        $row['permission_keys'] = is_string($row['permission_keys_csv']) && $row['permission_keys_csv'] !== ''
            ? explode(',', $row['permission_keys_csv'])
            : [];
        unset($row['permission_keys_csv']);
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
     * @param array<string, string> $metadata
     */
    private function audit(
        int $actorOperatorId,
        int $actorAccountId,
        string $eventType,
        string $action,
        string $targetType,
        string $targetId,
        string $requestId,
        ?array $before,
        ?array $after,
        array $metadata,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_platform_audit_event (
    event_type, action, outcome, operator_id, account_id, target_type, target_id,
    request_id, before_json, after_json, metadata_json, occurred_at
) VALUES (
    :event_type, :action, 'success', :operator_id, :account_id, :target_type, :target_id,
    :request_id, :before_json, :after_json, :metadata_json, :occurred_at
)
SQL, [
            'event_type' => $eventType,
            'action' => $action,
            'operator_id' => $actorOperatorId,
            'account_id' => $actorAccountId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_id' => $requestId,
            'before_json' => $this->json($before),
            'after_json' => $this->json($after),
            'metadata_json' => $this->json($metadata),
            'occurred_at' => $this->now(),
        ]);
    }

    /** @param array<string, mixed>|null $value */
    private function json(?array $value): ?string
    {
        try {
            return $value === null ? null : json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new AdminAccessException('AUDIT_SERIALIZATION_FAILED', 500, $exception->getMessage());
        }
    }

    /** @param array<string, int|string|null> $parameters */
    private function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    /** @param array<string, int|string|null> $parameters
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $parameters = []): ?array
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function statement(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new AdminAccessException('DATABASE_ERROR', 500, 'Could not prepare the database operation.');
        }

        return $statement;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

    /** @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transaction(callable $operation): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw $exception;
        }
    }
}
