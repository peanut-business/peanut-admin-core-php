<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Identity\SelfService;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOStatement;
use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use SensitiveParameter;
use Throwable;

final readonly class AccountSelfService
{
    private const PASSWORD_CHANGE_RATE_LIMIT = 5;
    private const PASSWORD_CHANGE_RATE_WINDOW = '-15 minutes';
    public const PASSWORD_CHANGE_RETRY_AFTER_SECONDS = 900;

    public function __construct(
        private PDO $pdo,
        private PasswordHasher $passwords = new PasswordHasher(),
    ) {}

    /** @return array<string, mixed> */
    public function profile(int $tenantId, int $memberId, int $accountId): array
    {
        $row = $this->profileRow($tenantId, $memberId, $accountId);
        if ($row === null) {
            throw AdminAccessException::conflict(
                'ACCOUNT_CREDENTIAL_UNAVAILABLE',
                'The account credential is not available.',
            );
        }

        return $this->profileFromRow($row);
    }

    /** @return array<string, mixed>|null */
    private function profileRow(int $tenantId, int $memberId, int $accountId, bool $forUpdate = false): ?array
    {
        $sql = <<<'SQL'
SELECT
    a.id,
    a.display_name,
    a.avatar_uri,
    c.kind,
    c.identifier_type,
    c.identifier_normalized,
    c.verified_at,
    c.secret_changed_at
FROM pa_account a
JOIN pa_credential c ON c.account_id = a.id
JOIN pa_tenant_member m ON m.account_id = a.id
JOIN pa_tenant t ON t.id = m.tenant_id
WHERE a.id = :account_id
  AND m.tenant_id = :tenant_id
  AND m.id = :member_id
  AND m.status = 'active'
  AND t.status = 'active'
  AND a.status = 'active'
  AND c.kind = 'email_password'
  AND c.identifier_type = 'email'
  AND c.status = 'active'
ORDER BY c.id
LIMIT 1
SQL;
        if ($forUpdate) {
            $sql .= "\nFOR UPDATE";
        }

        return $this->fetchOne($sql, [
            'account_id' => $accountId,
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function profileFromRow(
        array $row,
        ?string $displayName = null,
        string|null|false $avatarUri = false,
    ): array {
        return [
            'account_id' => (string) $row['id'],
            'display_name' => $displayName ?? (string) $row['display_name'],
            'avatar_uri' => $avatarUri === false
                ? ($row['avatar_uri'] === null ? null : (string) $row['avatar_uri'])
                : $avatarUri,
            'credential' => [
                'kind' => (string) $row['kind'],
                'identifier_type' => (string) $row['identifier_type'],
                'identifier_masked' => $this->maskEmail((string) $row['identifier_normalized']),
                'verified_at' => (string) $row['verified_at'],
                'secret_changed_at' => (string) $row['secret_changed_at'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function updateProfile(
        int $tenantId,
        int $memberId,
        int $accountId,
        string $displayName,
        ?string $avatarUri,
        string $requestId,
    ): array {
        $displayName = $this->displayName($displayName);
        $avatarUri = $this->avatarUri($avatarUri);

        return $this->transaction(function () use (
            $tenantId,
            $memberId,
            $accountId,
            $displayName,
            $avatarUri,
            $requestId,
        ): array {
            $current = $this->profileRow($tenantId, $memberId, $accountId, true);
            if ($current === null) {
                throw AdminAccessException::conflict(
                    'ACCOUNT_CREDENTIAL_UNAVAILABLE',
                    'The account credential is not available.',
                );
            }

            $now = $this->now();
            $this->execute(<<<'SQL'
UPDATE pa_account
SET display_name = :display_name, avatar_uri = :avatar_uri, updated_at = :updated_at
WHERE id = :account_id AND status = 'active'
SQL, [
                'display_name' => $displayName,
                'avatar_uri' => $avatarUri,
                'updated_at' => $now,
                'account_id' => $accountId,
            ]);

            $changedFields = [];
            if ((string) $current['display_name'] !== $displayName) {
                $changedFields[] = 'display_name';
            }
            $currentAvatar = $current['avatar_uri'] === null ? null : (string) $current['avatar_uri'];
            if ($currentAvatar !== $avatarUri) {
                $changedFields[] = 'avatar_uri';
            }
            $this->tenantAudit(
                $tenantId,
                $memberId,
                $accountId,
                'account.profile.changed',
                'account.profile.changed',
                $requestId,
                ['changed_fields' => $changedFields],
                $now,
            );

            return $this->profileFromRow($current, $displayName, $avatarUri);
        });
    }

    public function changePassword(
        int $tenantId,
        int $memberId,
        int $accountId,
        string $sessionKey,
        #[SensitiveParameter]
        string $currentPassword,
        #[SensitiveParameter]
        string $newPassword,
        string $ipAddress,
        ?string $userAgent,
        string $requestId,
    ): void {
        if ($currentPassword === '' || strlen($currentPassword) > 1_024) {
            throw AdminAccessException::invalid('CURRENT_PASSWORD_INVALID', 'The current password is invalid.');
        }
        try {
            $this->passwords->assertValid($newPassword);
        } catch (\RuntimeException) {
            throw AdminAccessException::invalid(
                'NEW_PASSWORD_INVALID',
                sprintf(
                    'The new password must contain between %d and %d bytes.',
                    $this->passwords->minimumLength(),
                    $this->passwords->maximumLength(),
                ),
            );
        }

        $ipLockName = $this->passwordChangeIpLockName($ipAddress);
        $this->acquirePasswordChangeIpLock($ipLockName);
        try {
            $error = $this->transaction(function () use (
                $tenantId,
                $memberId,
                $accountId,
                $sessionKey,
                $currentPassword,
                $newPassword,
                $ipAddress,
                $userAgent,
                $requestId,
            ): ?AdminAccessException {
                $credential = $this->fetchOne(<<<'SQL'
SELECT c.id, c.secret_hash
FROM pa_credential c
JOIN pa_account a ON a.id = c.account_id
JOIN pa_tenant_member m ON m.account_id = a.id
JOIN pa_tenant t ON t.id = m.tenant_id
WHERE c.account_id = :account_id
  AND m.tenant_id = :tenant_id
  AND m.id = :member_id
  AND m.status = 'active'
  AND t.status = 'active'
  AND c.kind = 'email_password'
  AND c.identifier_type = 'email'
  AND c.status = 'active'
  AND a.status = 'active'
ORDER BY c.id
LIMIT 1
FOR UPDATE
SQL, [
                    'account_id' => $accountId,
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                ]);
                if ($credential === null) {
                    throw AdminAccessException::conflict(
                        'ACCOUNT_CREDENTIAL_UNAVAILABLE',
                        'The account credential is not available.',
                    );
                }

                $now = $this->now();
                $deniedCounts = $this->passwordChangeDeniedCounts($accountId, $ipAddress, $now);
                if ($deniedCounts['account'] >= self::PASSWORD_CHANGE_RATE_LIMIT
                    || $deniedCounts['ip'] >= self::PASSWORD_CHANGE_RATE_LIMIT) {
                    $this->authEvent(
                        'password_change_rate_limited',
                        'denied',
                        'rate_limited',
                        $accountId,
                        (int) $credential['id'],
                        $sessionKey,
                        $requestId,
                        $ipAddress,
                        $userAgent,
                        $now,
                    );

                    return new AdminAccessException(
                        'PASSWORD_CHANGE_RATE_LIMITED',
                        429,
                        'Too many password change attempts. Try again later.',
                    );
                }
                if (!$this->passwords->verify($currentPassword, (string) $credential['secret_hash'])) {
                    $this->authEvent(
                        'password_change_denied',
                        'denied',
                        'current_password_invalid',
                        $accountId,
                        (int) $credential['id'],
                        $sessionKey,
                        $requestId,
                        $ipAddress,
                        $userAgent,
                        $now,
                    );

                    return AdminAccessException::invalid(
                        'CURRENT_PASSWORD_INVALID',
                        'The current password is invalid.',
                    );
                }
                if (hash_equals($currentPassword, $newPassword)) {
                    return AdminAccessException::invalid(
                        'PASSWORD_UNCHANGED',
                        'The new password must be different.',
                    );
                }

                $this->execute(<<<'SQL'
UPDATE pa_credential
SET secret_hash = :secret_hash,
    failed_attempts = 0,
    locked_until = NULL,
    secret_changed_at = :secret_changed_at,
    revision = revision + 1,
    updated_at = :updated_at
WHERE id = :credential_id AND status = 'active'
SQL, [
                    'secret_hash' => $this->passwords->hash($newPassword),
                    'secret_changed_at' => $now,
                    'updated_at' => $now,
                    'credential_id' => (int) $credential['id'],
                ]);
                $this->execute(<<<'SQL'
UPDATE pa_account
SET security_revision = security_revision + 1, updated_at = :updated_at
WHERE id = :account_id AND status = 'active'
SQL, ['updated_at' => $now, 'account_id' => $accountId]);

                $this->revokeSessionTokens('pa_tenant_session', 'pa_tenant_session_token', $accountId, $now);
                $this->revokeSessionTokens('pa_platform_session', 'pa_platform_session_token', $accountId, $now);
                $this->revokeSessions('pa_tenant_session', $accountId, $now);
                $this->revokeSessions('pa_platform_session', $accountId, $now);
                $this->revokeLoginChallenges($accountId, $now);

                $this->authEvent(
                    'password_changed',
                    'success',
                    null,
                    $accountId,
                    (int) $credential['id'],
                    $sessionKey,
                    $requestId,
                    $ipAddress,
                    $userAgent,
                    $now,
                );
                $this->tenantAudit(
                    $tenantId,
                    $memberId,
                    $accountId,
                    'account.password.changed',
                    'account.password.changed',
                    $requestId,
                    ['revoked_all_sessions' => true],
                    $now,
                );

                return null;
            });
        } finally {
            $this->releasePasswordChangeIpLock($ipLockName);
        }

        if ($error !== null) {
            throw $error;
        }
    }

    private function displayName(string $displayName): string
    {
        $displayName = trim($displayName);
        if ($displayName === ''
            || preg_match('//u', $displayName) !== 1
            || mb_strlen($displayName, 'UTF-8') > 120) {
            throw AdminAccessException::invalid(
                'ACCOUNT_PROFILE_INVALID',
                'The display name must contain between 1 and 120 characters.',
            );
        }

        return $displayName;
    }

    private function avatarUri(?string $avatarUri): ?string
    {
        if ($avatarUri === null || trim($avatarUri) === '') {
            return null;
        }
        $avatarUri = trim($avatarUri);
        $parts = parse_url($avatarUri);
        if (strlen($avatarUri) > 512
            || filter_var($avatarUri, FILTER_VALIDATE_URL) === false
            || !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || !is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw AdminAccessException::invalid(
                'AVATAR_URI_INVALID',
                'The avatar URI must be an absolute HTTPS URL.',
            );
        }

        return $avatarUri;
    }

    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $prefix = $local === '' ? '*' : substr($local, 0, 1);

        return $prefix . '***@' . $domain;
    }

    private function revokeSessionTokens(string $sessionTable, string $tokenTable, int $accountId, string $now): void
    {
        $this->execute(<<<SQL
UPDATE `{$tokenTable}` token
JOIN `{$sessionTable}` session ON session.id = token.session_id
SET token.status = 'revoked', token.revoked_at = :revoked_at
WHERE session.account_id = :account_id AND token.status = 'active'
SQL, ['revoked_at' => $now, 'account_id' => $accountId]);
    }

    private function revokeSessions(string $table, int $accountId, string $now): void
    {
        $this->execute(<<<SQL
UPDATE `{$table}`
SET status = 'revoked', revoked_at = :revoked_at,
    revoke_reason = 'credential_changed', updated_at = :updated_at
WHERE account_id = :account_id AND status = 'active'
SQL, [
            'revoked_at' => $now,
            'updated_at' => $now,
            'account_id' => $accountId,
        ]);
    }

    private function revokeLoginChallenges(int $accountId, string $now): void
    {
        $this->execute(<<<'SQL'
UPDATE pa_login_challenge
SET status = 'revoked', revoked_at = :revoked_at
WHERE account_id = :account_id AND status = 'active'
SQL, ['revoked_at' => $now, 'account_id' => $accountId]);
    }

    /** @return array{account: int, ip: int} */
    private function passwordChangeDeniedCounts(int $accountId, string $ipAddress, string $now): array
    {
        $since = (new DateTimeImmutable($now, new DateTimeZone('UTC')))
            ->modify(self::PASSWORD_CHANGE_RATE_WINDOW)
            ->format('Y-m-d H:i:s.v');
        $account = $this->fetchOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM pa_auth_security_event
WHERE event_type = 'password_change_denied'
  AND outcome = 'denied'
  AND occurred_at >= :since_at
  AND account_id = :account_id
SQL, [
            'since_at' => $since,
            'account_id' => $accountId,
        ]);
        $ip = $this->fetchOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM pa_auth_security_event
WHERE event_type = 'password_change_denied'
  AND outcome = 'denied'
  AND occurred_at >= :since_at
  AND ip_address = :ip_address
SQL, [
            'since_at' => $since,
            'ip_address' => $ipAddress,
        ]);

        return [
            'account' => $account === null ? 0 : (int) $account['aggregate'],
            'ip' => $ip === null ? 0 : (int) $ip['aggregate'],
        ];
    }

    private function passwordChangeIpLockName(string $ipAddress): string
    {
        return 'pa-pwd:' . substr(hash('sha256', $ipAddress), 0, 57);
    }

    private function acquirePasswordChangeIpLock(string $lockName): void
    {
        $row = $this->fetchOne('SELECT GET_LOCK(:lock_name, 10) AS acquired', [
            'lock_name' => $lockName,
        ]);
        if ($row === null || (int) $row['acquired'] !== 1) {
            throw new AdminAccessException(
                'DATABASE_ERROR',
                500,
                'Could not serialize password change attempts.',
            );
        }
    }

    private function releasePasswordChangeIpLock(string $lockName): void
    {
        try {
            $row = $this->fetchOne('SELECT RELEASE_LOCK(:lock_name) AS released', [
                'lock_name' => $lockName,
            ]);
            if ($row !== null && (int) ($row['released'] ?? 0) === 1) {
                return;
            }
        } catch (Throwable) {
            // Fall through to connection-wide cleanup without replacing the committed outcome.
        }

        try {
            $this->pdo->query('SELECT RELEASE_ALL_LOCKS()');
        } catch (Throwable) {
            // A broken connection releases its MySQL locks when the request-scoped PDO is destroyed.
        }
    }

    /** @param array<string, bool|list<string>> $metadata */
    private function tenantAudit(
        int $tenantId,
        int $memberId,
        int $accountId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata,
        string $now,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant_audit_event (
    tenant_id, event_type, action, outcome,
    actor_tenant_id, actor_tenant_member_id, actor_account_id, actor_type,
    target_resource_type, target_resource_id, target_count,
    request_id, metadata_json, occurred_at
) VALUES (
    :tenant_id, :event_type, :action, 'success',
    :actor_tenant_id, :member_id, :account_id, 'member',
    'account', :target_id, 1,
    :request_id, :metadata_json, :occurred_at
)
SQL, [
            'tenant_id' => $tenantId,
            'actor_tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'member_id' => $memberId,
            'account_id' => $accountId,
            'target_id' => (string) $accountId,
            'request_id' => $requestId,
            'metadata_json' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurred_at' => $now,
        ]);
    }

    private function authEvent(
        string $eventType,
        string $outcome,
        ?string $reasonCode,
        int $accountId,
        int $credentialId,
        string $sessionKey,
        string $requestId,
        string $ipAddress,
        ?string $userAgent,
        string $now,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_auth_security_event (
    audience, event_type, outcome, reason_code, account_id, credential_id,
    session_key, request_id, ip_address, user_agent_hash, occurred_at
) VALUES (
    'tenant', :event_type, :outcome, :reason_code, :account_id, :credential_id,
    :session_key, :request_id, :ip_address, :user_agent_hash, :occurred_at
)
SQL, [
            'event_type' => $eventType,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'account_id' => $accountId,
            'credential_id' => $credentialId,
            'session_key' => $sessionKey,
            'request_id' => $requestId,
            'ip_address' => $ipAddress,
            'user_agent_hash' => $userAgent === null ? null : hash('sha256', $userAgent),
            'occurred_at' => $now,
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

            throw $exception;
        }
    }
}
