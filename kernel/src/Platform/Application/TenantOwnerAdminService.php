<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Platform\Application;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use Throwable;

final readonly class TenantOwnerAdminService
{
    public function __construct(
        private PDO $pdo,
        private PasswordHasher $passwords = new PasswordHasher(),
    ) {}

    /** @return array<string, mixed> */
    public function createCandidate(
        int $operatorId,
        int $operatorAccountId,
        int $tenantId,
        string $email,
        string $displayName,
        ?string $initialPassword,
        string $requestId,
    ): array {
        try {
            $normalizedEmail = EmailAddress::fromString($email)->value();
        } catch (InvalidArgumentException) {
            throw AdminAccessException::invalid('EMAIL_INVALID', 'The email address is invalid.');
        }
        $identifier = $normalizedEmail;

        return $this->transaction(function () use (
            $operatorId,
            $operatorAccountId,
            $tenantId,
            $identifier,
            $displayName,
            $initialPassword,
            $requestId,
        ): array {
            $this->requireOperator($operatorId, $operatorAccountId);
            $this->requireProvisioningTenant($tenantId);
            $ownerRole = $this->fetchOne(<<<'SQL'
SELECT id FROM pa_role
WHERE tenant_id = :tenant_id AND `key` = 'core.tenant-owner'
  AND is_builtin = 1 AND status = 'active'
FOR UPDATE
SQL, ['tenant_id' => $tenantId]);
            if ($ownerRole === null) {
                throw AdminAccessException::conflict(
                    'TENANT_OWNER_ROLE_MISSING',
                    'The provisioning tenant does not contain its built-in owner role.',
                );
            }
            if ($this->ownerCandidateCount($tenantId) !== 0) {
                throw AdminAccessException::conflict(
                    'TENANT_OWNER_CANDIDATE_EXISTS',
                    'A pending or active owner candidate already exists.',
                );
            }

            $credential = $this->fetchOne(<<<'SQL'
SELECT id, account_id, status
FROM pa_credential
WHERE identifier_type = 'email' AND identifier_normalized = :identifier
FOR UPDATE
SQL, ['identifier' => $identifier]);
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
                $account = $this->fetchOne('SELECT status FROM pa_account WHERE id = :id FOR UPDATE', ['id' => $accountId]);
                if ($account === null || $account['status'] !== 'active') {
                    throw AdminAccessException::conflict('ACCOUNT_INACTIVE', 'The account is inactive.');
                }
            }

            if ($this->fetchOne(
                'SELECT id FROM pa_tenant_member WHERE tenant_id = :tenant_id AND account_id = :account_id FOR UPDATE',
                ['tenant_id' => $tenantId, 'account_id' => $accountId],
            ) !== null) {
                throw AdminAccessException::conflict(
                    'TENANT_MEMBER_ALREADY_EXISTS',
                    'The account already has a member record in this tenant.',
                );
            }

            $now = $this->now();
            $this->execute(<<<'SQL'
INSERT INTO pa_tenant_member (tenant_id, account_id, display_name, status, created_at, updated_at)
VALUES (:tenant_id, :account_id, :display_name, 'pending', :created_at, :updated_at)
SQL, [
                'tenant_id' => $tenantId,
                'account_id' => $accountId,
                'display_name' => $displayName,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $memberId = (int) $this->pdo->lastInsertId();
            $this->execute(<<<'SQL'
INSERT INTO pa_member_role (tenant_id, tenant_member_id, role_id, assigned_at)
VALUES (:tenant_id, :member_id, :role_id, :assigned_at)
SQL, [
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'role_id' => (int) $ownerRole['id'],
                'assigned_at' => $now,
            ]);
            $this->execute(<<<'SQL'
UPDATE pa_tenant_member
SET authorization_revision = authorization_revision + 1, updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :member_id
SQL, ['updated_at' => $now, 'tenant_id' => $tenantId, 'member_id' => $memberId]);
            $this->execute(<<<'SQL'
UPDATE pa_tenant SET authorization_revision = authorization_revision + 1, updated_at = :updated_at WHERE id = :tenant_id
SQL, ['updated_at' => $now, 'tenant_id' => $tenantId]);
            $this->platformAudit(
                $operatorId,
                $operatorAccountId,
                'tenant.owner-candidate.created',
                'platform.tenant.provision-owner',
                $tenantId,
                $memberId,
                $requestId,
                null,
            );

            return $this->candidate($tenantId, $memberId);
        });
    }

    /** @return array<string, mixed> */
    public function activateCandidate(
        int $operatorId,
        int $operatorAccountId,
        int $tenantId,
        int $memberId,
        int $expectedRevision,
        string $idempotencyKey,
        string $changeReason,
        string $requestId,
    ): array {
        if ($idempotencyKey === '') {
            throw new AdminAccessException('IDEMPOTENCY_KEY_REQUIRED', 428, 'Idempotency-Key is required.');
        }
        if (trim($changeReason) === '') {
            throw AdminAccessException::invalid('CHANGE_REASON_REQUIRED', 'A change reason is required.');
        }
        $idempotencyHash = hash('sha256', implode('|', [
            $idempotencyKey,
            (string) $tenantId,
            (string) $memberId,
            (string) $expectedRevision,
            $changeReason,
        ]));

        return $this->transaction(function () use (
            $operatorId,
            $operatorAccountId,
            $tenantId,
            $memberId,
            $expectedRevision,
            $idempotencyHash,
            $changeReason,
            $requestId,
        ): array {
            $this->requireOperator($operatorId, $operatorAccountId);
            $this->requireProvisioningTenant($tenantId);
            $member = $this->fetchOne(<<<'SQL'
SELECT tm.*, a.status AS account_status
FROM pa_tenant_member tm
JOIN pa_account a ON a.id = tm.account_id
WHERE tm.tenant_id = :tenant_id AND tm.id = :member_id
FOR UPDATE
SQL, ['tenant_id' => $tenantId, 'member_id' => $memberId]);
            if ($member === null) {
                throw AdminAccessException::notFound();
            }
            if ($member['status'] === 'active') {
                if ($this->activationWasApplied($operatorId, $tenantId, $memberId, $idempotencyHash)) {
                    return $this->candidate($tenantId, $memberId);
                }
                throw AdminAccessException::conflict('OWNER_ALREADY_ACTIVE', 'The owner candidate is already active.');
            }
            if ($member['status'] !== 'pending') {
                throw AdminAccessException::conflict('OWNER_CANDIDATE_STATUS_INVALID', 'Only a pending owner can be activated.');
            }
            if ((int) $member['authorization_revision'] !== $expectedRevision) {
                throw AdminAccessException::revisionMismatch();
            }
            if ($member['account_status'] !== 'active' || !$this->activeCredentialExists((int) $member['account_id'])) {
                throw AdminAccessException::conflict('OWNER_ACCOUNT_INACTIVE', 'The owner account and credential must be active.');
            }
            if (!$this->memberHasOwnerRole($tenantId, $memberId)) {
                throw AdminAccessException::conflict('TENANT_OWNER_ROLE_MISSING', 'The candidate does not hold the owner role.');
            }

            $now = $this->now();
            if ($this->execute(<<<'SQL'
UPDATE pa_tenant_member
SET status = 'active', joined_at = :joined_at,
    security_revision = security_revision + 1,
    authorization_revision = authorization_revision + 1,
    updated_at = :updated_at
WHERE tenant_id = :tenant_id AND id = :member_id
  AND status = 'pending' AND authorization_revision = :expected_revision
SQL, [
                'joined_at' => $now,
                'updated_at' => $now,
                'tenant_id' => $tenantId,
                'member_id' => $memberId,
                'expected_revision' => $expectedRevision,
            ]) !== 1) {
                throw AdminAccessException::revisionMismatch();
            }
            $this->execute(<<<'SQL'
UPDATE pa_tenant SET authorization_revision = authorization_revision + 1, updated_at = :updated_at WHERE id = :tenant_id
SQL, ['updated_at' => $now, 'tenant_id' => $tenantId]);
            $metadata = [
                'tenant_id' => (string) $tenantId,
                'member_id' => (string) $memberId,
                'idempotency_hash' => $idempotencyHash,
                'change_reason' => $changeReason,
            ];
            $this->platformAudit(
                $operatorId,
                $operatorAccountId,
                'tenant.owner-candidate.activated',
                'platform.tenant.provision-owner',
                $tenantId,
                $memberId,
                $requestId,
                $metadata,
            );
            $this->tenantAudit($operatorId, $operatorAccountId, $tenantId, $memberId, $requestId, $metadata);

            return $this->candidate($tenantId, $memberId);
        });
    }

    /** @return array<string, mixed> */
    private function candidate(int $tenantId, int $memberId): array
    {
        $row = $this->fetchOne(<<<'SQL'
SELECT id, account_id, display_name, status, security_revision, authorization_revision
FROM pa_tenant_member WHERE tenant_id = :tenant_id AND id = :member_id
SQL, ['tenant_id' => $tenantId, 'member_id' => $memberId]) ?? throw AdminAccessException::notFound();

        return [
            'tenant_id' => (string) $tenantId,
            'member' => [
                'id' => (string) $row['id'],
                'account_id' => (string) $row['account_id'],
                'display_name' => $row['display_name'],
                'status' => $row['status'],
                'security_revision' => (string) $row['security_revision'],
                'revision' => (string) $row['authorization_revision'],
                'role_keys' => ['core.tenant-owner'],
            ],
        ];
    }

    private function requireOperator(int $operatorId, int $accountId): void
    {
        if ($this->fetchOne(<<<'SQL'
SELECT id FROM pa_platform_operator
WHERE id = :operator_id AND account_id = :account_id AND status = 'active'
FOR UPDATE
SQL, ['operator_id' => $operatorId, 'account_id' => $accountId]) === null) {
            throw new AdminAccessException('PLATFORM_OPERATOR_INVALID', 403, 'An active platform operator is required.');
        }
    }

    private function requireProvisioningTenant(int $tenantId): void
    {
        if ($this->fetchOne(
            "SELECT id FROM pa_tenant WHERE id = :tenant_id AND status = 'provisioning' FOR UPDATE",
            ['tenant_id' => $tenantId],
        ) === null) {
            throw AdminAccessException::conflict(
                'TENANT_NOT_PROVISIONING',
                'Owner provisioning is only available while the tenant is provisioning.',
            );
        }
    }

    private function ownerCandidateCount(int $tenantId): int
    {
        return $this->scalar(<<<'SQL'
SELECT COUNT(DISTINCT tm.id)
FROM pa_tenant_member tm
JOIN pa_member_role mr ON mr.tenant_id = tm.tenant_id AND mr.tenant_member_id = tm.id
JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE tm.tenant_id = :tenant_id AND tm.status IN ('pending', 'active')
  AND r.`key` = 'core.tenant-owner' AND r.is_builtin = 1 AND r.status = 'active'
SQL, ['tenant_id' => $tenantId]);
    }

    private function createAccountAndCredential(string $identifier, string $displayName, string $password): int
    {
        $now = $this->now();
        $this->execute(
            'INSERT INTO pa_account (display_name, created_at, updated_at) VALUES (:name, :created_at, :updated_at)',
            ['name' => $displayName, 'created_at' => $now, 'updated_at' => $now],
        );
        $accountId = (int) $this->pdo->lastInsertId();
        $this->execute(<<<'SQL'
INSERT INTO pa_credential (
    account_id, kind, identifier_type, identifier_normalized, secret_hash,
    verified_at, secret_changed_at, created_at, updated_at
) VALUES (
    :account_id, 'email_password', 'email', :identifier, :secret_hash,
    :verified_at, :secret_changed_at, :created_at, :updated_at
)
SQL, [
            'account_id' => $accountId,
            'identifier' => $identifier,
            'secret_hash' => $this->passwords->hash($password),
            'verified_at' => $now,
            'secret_changed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $accountId;
    }

    private function memberHasOwnerRole(int $tenantId, int $memberId): bool
    {
        return $this->fetchOne(<<<'SQL'
SELECT mr.id
FROM pa_member_role mr
JOIN pa_role r ON r.tenant_id = mr.tenant_id AND r.id = mr.role_id
WHERE mr.tenant_id = :tenant_id AND mr.tenant_member_id = :member_id
  AND r.`key` = 'core.tenant-owner' AND r.is_builtin = 1 AND r.status = 'active'
LIMIT 1
SQL, ['tenant_id' => $tenantId, 'member_id' => $memberId]) !== null;
    }

    private function activeCredentialExists(int $accountId): bool
    {
        return $this->fetchOne(<<<'SQL'
SELECT id FROM pa_credential
WHERE account_id = :account_id AND status = 'active'
  AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP(3))
LIMIT 1
SQL, ['account_id' => $accountId]) !== null;
    }

    private function activationWasApplied(
        int $operatorId,
        int $tenantId,
        int $memberId,
        string $idempotencyHash,
    ): bool {
        return $this->fetchOne(<<<'SQL'
SELECT id FROM pa_platform_audit_event
WHERE operator_id = :operator_id
  AND event_type = 'tenant.owner-candidate.activated'
  AND target_type = 'tenant-owner-candidate'
  AND target_id = :member_id
  AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.tenant_id')) = :tenant_id
  AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.idempotency_hash')) = :idempotency_hash
LIMIT 1
SQL, [
            'operator_id' => $operatorId,
            'member_id' => (string) $memberId,
            'tenant_id' => (string) $tenantId,
            'idempotency_hash' => $idempotencyHash,
        ]) !== null;
    }

    /** @param array<string, string>|null $metadata */
    private function platformAudit(
        int $operatorId,
        int $operatorAccountId,
        string $eventType,
        string $action,
        int $tenantId,
        int $memberId,
        string $requestId,
        ?array $metadata,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_platform_audit_event (
    event_type, action, outcome, operator_id, account_id,
    target_type, target_id, request_id, metadata_json, occurred_at
) VALUES (
    :event_type, :action, 'success', :operator_id, :account_id,
    'tenant-owner-candidate', :target_id, :request_id, :metadata_json, :occurred_at
)
SQL, [
            'event_type' => $eventType,
            'action' => $action,
            'operator_id' => $operatorId,
            'account_id' => $operatorAccountId,
            'target_id' => (string) $memberId,
            'request_id' => $requestId,
            'metadata_json' => json_encode($metadata ?? ['tenant_id' => (string) $tenantId], JSON_THROW_ON_ERROR),
            'occurred_at' => $this->now(),
        ]);
    }

    /** @param array<string, string> $metadata */
    private function tenantAudit(
        int $operatorId,
        int $operatorAccountId,
        int $tenantId,
        int $memberId,
        string $requestId,
        array $metadata,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant_audit_event (
    tenant_id, event_type, action, outcome, actor_account_id,
    actor_platform_operator_id, actor_type, target_resource_type,
    target_resource_id, target_count, request_id, metadata_json, occurred_at
) VALUES (
    :tenant_id, 'tenant.owner-candidate.activated', 'platform.tenant.provision-owner',
    'success', :actor_account_id, :operator_id, 'platform_operator',
    'member', :member_id, 1, :request_id, :metadata_json, :occurred_at
)
SQL, [
            'tenant_id' => $tenantId,
            'actor_account_id' => $operatorAccountId,
            'operator_id' => $operatorId,
            'member_id' => (string) $memberId,
            'request_id' => $requestId,
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => $this->now(),
        ]);
    }

    /** @param array<string, int|string|null> $parameters */
    private function execute(string $sql, array $parameters = []): int
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);

        return $statement->rowCount();
    }

    /**
     * @param array<string, int|string|null> $parameters
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $parameters = []): ?array
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /** @param array<string, int|string|null> $parameters */
    private function scalar(string $sql, array $parameters = []): int
    {
        $statement = $this->statement($sql);
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
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

    /**
     * @template T
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
            if ($exception instanceof PDOException && $exception->getCode() === '23000') {
                throw AdminAccessException::conflict(
                    'TENANT_OWNER_CANDIDATE_CONFLICT',
                    'The owner candidate conflicts with an existing relation.',
                );
            }

            throw $exception;
        }
    }
}
