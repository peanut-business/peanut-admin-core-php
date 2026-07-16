<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Auth\PlatformAuthPrincipal;
use PeanutAdmin\Kernel\Auth\PlatformAuthRepository;
use PeanutAdmin\Kernel\Auth\PlatformSessionAuthenticationRecord;
use PeanutAdmin\Kernel\Auth\PlatformTokenPair;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Identity\AccountStatus;
use PeanutAdmin\Kernel\Identity\CredentialStatus;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoRepository;
use PeanutAdmin\Kernel\Platform\PlatformOperatorStatus;

final class PdoPlatformAuthRepository extends PdoRepository implements PlatformAuthRepository
{
    public function failedLoginCountByIp(string $ipAddress, DateTimeImmutable $since): int
    {
        $row = $this->fetchOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM pa_auth_security_event
WHERE event_type = 'login_failed'
  AND outcome = 'denied'
  AND ip_address = :ip_address
  AND occurred_at >= :since_at
SQL, [
            'ip_address' => $ipAddress,
            'since_at' => $this->format($since),
        ]);

        return $row === null ? 0 : (int) $row['aggregate'];
    }

    public function failedLoginCountByIdentifier(string $identifierHmac, DateTimeImmutable $since): int
    {
        $row = $this->fetchOne(<<<'SQL'
SELECT COUNT(*) AS aggregate
FROM pa_auth_security_event
WHERE event_type = 'login_failed'
  AND outcome = 'denied'
  AND identifier_hmac = :identifier_hmac
  AND occurred_at >= :since_at
SQL, [
            'identifier_hmac' => $identifierHmac,
            'since_at' => $this->format($since),
        ]);

        return $row === null ? 0 : (int) $row['aggregate'];
    }

    public function principalByEmail(EmailAddress $email, bool $forUpdate = false): ?PlatformAuthPrincipal
    {
        $row = $this->fetchOne(
            <<<'SQL'
SELECT
    c.id AS credential_id,
    c.account_id,
    c.secret_hash,
    c.status AS credential_status,
    c.failed_attempts,
    c.locked_until,
    c.expires_at,
    a.status AS account_status,
    a.security_revision AS account_security_revision,
    po.id AS operator_id,
    po.status AS operator_status,
    po.security_revision AS operator_security_revision
FROM pa_credential c
JOIN pa_account a ON a.id = c.account_id
JOIN pa_platform_operator po ON po.account_id = a.id
WHERE c.identifier_type = 'email' AND c.identifier_normalized = :email
SQL . ($forUpdate ? ' FOR UPDATE' : ''),
            ['email' => $email->value()],
        );
        if ($row === null) {
            return null;
        }

        return new PlatformAuthPrincipal(
            (int) $row['credential_id'],
            (int) $row['account_id'],
            (string) $row['secret_hash'],
            CredentialStatus::from((string) $row['credential_status']),
            (int) $row['failed_attempts'],
            $this->nullableDate($row['locked_until']),
            $this->nullableDate($row['expires_at']),
            AccountStatus::from((string) $row['account_status']),
            (int) $row['account_security_revision'],
            (int) $row['operator_id'],
            PlatformOperatorStatus::from((string) $row['operator_status']),
            (int) $row['operator_security_revision'],
        );
    }

    public function registerFailedLogin(
        ?PlatformAuthPrincipal $principal,
        string $identifierHmac,
        string $ipAddress,
        ?string $userAgentHash,
        string $requestId,
        DateTimeImmutable $now,
    ): void {
        $credentialLocked = false;
        if ($principal !== null) {
            $lockIsActive = $principal->credentialStatus === CredentialStatus::Locked
                && $principal->lockedUntil !== null
                && $now < $principal->lockedUntil;
            if (!$lockIsActive) {
                $attempts = $principal->credentialStatus === CredentialStatus::Locked
                    ? 1
                    : $principal->failedAttempts + 1;
                $lockedUntil = $attempts >= 5 ? $now->modify('+15 minutes') : null;
                $credentialLocked = $lockedUntil !== null;
                $this->execute(<<<'SQL'
UPDATE pa_credential
SET failed_attempts = :failed_attempts,
    status = CASE WHEN :lock_status = 1 THEN 'locked' ELSE 'active' END,
    locked_until = :locked_until,
    revision = revision + 1,
    updated_at = :updated_at
WHERE id = :credential_id
SQL, [
                    'failed_attempts' => $attempts,
                    'lock_status' => $lockedUntil === null ? 0 : 1,
                    'locked_until' => $lockedUntil === null ? null : $this->format($lockedUntil),
                    'updated_at' => $this->format($now),
                    'credential_id' => $principal->credentialId,
                ]);
                if ($credentialLocked) {
                    $this->execute(<<<'SQL'
UPDATE pa_account
SET security_revision = security_revision + 1, updated_at = :updated_at
WHERE id = :account_id
SQL, [
                        'updated_at' => $this->format($now),
                        'account_id' => $principal->accountId,
                    ]);
                }
            }
        }

        $this->recordEvent(
            'login_failed',
            'denied',
            'invalid_credentials',
            $principal?->accountId,
            $principal?->credentialId,
            null,
            $identifierHmac,
            $requestId,
            $ipAddress,
            $userAgentHash,
            $now,
        );
        if ($credentialLocked && $principal !== null) {
            $this->recordEvent(
                'credential_locked',
                'denied',
                'failed_attempt_limit',
                $principal->accountId,
                $principal->credentialId,
                null,
                $identifierHmac,
                $requestId,
                $ipAddress,
                $userAgentHash,
                $now,
            );
        }
    }

    public function registerSuccessfulLogin(
        PlatformAuthPrincipal $principal,
        ?string $replacementSecretHash,
        DateTimeImmutable $now,
    ): void {
        $this->execute(<<<'SQL'
UPDATE pa_credential
SET status = 'active', failed_attempts = 0, locked_until = NULL,
    secret_hash = COALESCE(:secret_hash, secret_hash),
    secret_changed_at = CASE WHEN :secret_changed = 1 THEN :secret_changed_at ELSE secret_changed_at END,
    revision = revision + 1,
    last_used_at = :last_used_at, updated_at = :updated_at
WHERE id = :credential_id
SQL, [
            'secret_hash' => $replacementSecretHash,
            'secret_changed' => $replacementSecretHash === null ? 0 : 1,
            'secret_changed_at' => $this->format($now),
            'last_used_at' => $this->format($now),
            'updated_at' => $this->format($now),
            'credential_id' => $principal->credentialId,
        ]);
        $this->execute(<<<'SQL'
UPDATE pa_account SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :account_id
SQL, [
            'last_login_at' => $this->format($now),
            'updated_at' => $this->format($now),
            'account_id' => $principal->accountId,
        ]);
    }

    public function createSession(
        PlatformAuthPrincipal $principal,
        string $sessionKey,
        PlatformTokenPair $tokens,
        string $ipAddress,
        ?string $userAgentHash,
        DateTimeImmutable $now,
    ): ValidatedPlatformSession {
        $this->execute(<<<'SQL'
INSERT INTO pa_platform_session (
    session_key, account_id, platform_operator_id, client_key,
    account_security_revision, operator_security_revision,
    issued_at, last_seen_at, idle_expires_at, absolute_expires_at,
    ip_address, user_agent_hash, created_at, updated_at
) VALUES (
    :session_key, :account_id, :operator_id, 'platform-web',
    :account_revision, :operator_revision,
    :issued_at, :last_seen_at, :idle_expires_at, :absolute_expires_at,
    :ip_address, :user_agent_hash, :created_at, :updated_at
)
SQL, [
            'session_key' => $sessionKey,
            'account_id' => $principal->accountId,
            'operator_id' => $principal->operatorId,
            'account_revision' => $principal->accountSecurityRevision,
            'operator_revision' => $principal->operatorSecurityRevision,
            'issued_at' => $this->format($now),
            'last_seen_at' => $this->format($now),
            'idle_expires_at' => $this->format($now->modify('+8 hours')),
            'absolute_expires_at' => $this->format($tokens->refreshExpiresAt),
            'ip_address' => $ipAddress,
            'user_agent_hash' => $userAgentHash,
            'created_at' => $this->format($now),
            'updated_at' => $this->format($now),
        ]);
        $sessionId = $this->lastInsertId();
        $this->insertToken($sessionId, 'access', $tokens->access->hash(), $tokens->accessExpiresAt, null, $now);
        $this->insertToken($sessionId, 'refresh', $tokens->refresh->hash(), $tokens->refreshExpiresAt, null, $now);

        return new ValidatedPlatformSession(
            $sessionId,
            $sessionKey,
            $principal->accountId,
            $principal->operatorId,
            'platform-web',
            $now,
        );
    }

    public function sessionByTokenHash(
        string $tokenHash,
        string $tokenType,
        bool $forUpdate = false,
    ): ?PlatformSessionAuthenticationRecord {
        $row = $this->fetchOne(
            <<<'SQL'
SELECT
    st.id AS token_id,
    st.token_type,
    st.status AS token_status,
    st.expires_at AS token_expires_at,
    s.id AS session_id,
    s.session_key,
    s.status AS session_status,
    s.account_id,
    s.platform_operator_id,
    s.client_key,
    s.issued_at,
    s.idle_expires_at,
    s.absolute_expires_at,
    s.account_security_revision,
    s.operator_security_revision,
    a.status AS account_status,
    a.security_revision AS current_account_security_revision,
    po.status AS operator_status,
    po.security_revision AS current_operator_security_revision
FROM pa_platform_session_token st
JOIN pa_platform_session s ON s.id = st.session_id
JOIN pa_account a ON a.id = s.account_id
JOIN pa_platform_operator po
  ON po.id = s.platform_operator_id
 AND po.account_id = s.account_id
WHERE st.token_hash = :token_hash AND st.token_type = :token_type
SQL . ($forUpdate ? ' FOR UPDATE' : ''),
            ['token_hash' => $tokenHash, 'token_type' => $tokenType],
        );
        if ($row === null) {
            return null;
        }

        return new PlatformSessionAuthenticationRecord(
            (int) $row['token_id'],
            (string) $row['token_type'],
            (string) $row['token_status'],
            $this->date((string) $row['token_expires_at']),
            (int) $row['session_id'],
            (string) $row['session_key'],
            (string) $row['session_status'],
            (int) $row['account_id'],
            (int) $row['platform_operator_id'],
            (string) $row['client_key'],
            $this->date((string) $row['issued_at']),
            $this->date((string) $row['idle_expires_at']),
            $this->date((string) $row['absolute_expires_at']),
            (int) $row['account_security_revision'],
            (int) $row['operator_security_revision'],
            AccountStatus::from((string) $row['account_status']),
            (int) $row['current_account_security_revision'],
            PlatformOperatorStatus::from((string) $row['operator_status']),
            (int) $row['current_operator_security_revision'],
        );
    }

    public function rotateTokens(
        PlatformSessionAuthenticationRecord $refresh,
        PlatformTokenPair $tokens,
        DateTimeImmutable $now,
    ): void {
        $this->execute(<<<'SQL'
UPDATE pa_platform_session_token SET status = 'used', used_at = :used_at
WHERE id = :token_id AND status = 'active'
SQL, ['used_at' => $this->format($now), 'token_id' => $refresh->tokenId]);
        $this->execute(<<<'SQL'
UPDATE pa_platform_session_token SET status = 'revoked', revoked_at = :revoked_at
WHERE session_id = :session_id AND token_type = 'access' AND status = 'active'
SQL, ['revoked_at' => $this->format($now), 'session_id' => $refresh->sessionId]);
        $this->insertToken(
            $refresh->sessionId,
            'access',
            $tokens->access->hash(),
            $tokens->accessExpiresAt,
            null,
            $now,
        );
        $newRefreshId = $this->insertToken(
            $refresh->sessionId,
            'refresh',
            $tokens->refresh->hash(),
            $tokens->refreshExpiresAt,
            $refresh->tokenId,
            $now,
        );
        $this->execute(<<<'SQL'
UPDATE pa_platform_session_token SET replaced_by_token_id = :replacement_id WHERE id = :token_id
SQL, ['replacement_id' => $newRefreshId, 'token_id' => $refresh->tokenId]);
        $this->execute(<<<'SQL'
UPDATE pa_platform_session
SET last_seen_at = :last_seen_at,
    idle_expires_at = LEAST(:idle_expires_at, absolute_expires_at),
    updated_at = :updated_at
WHERE id = :session_id
SQL, [
            'last_seen_at' => $this->format($now),
            'idle_expires_at' => $this->format($now->modify('+8 hours')),
            'updated_at' => $this->format($now),
            'session_id' => $refresh->sessionId,
        ]);
    }

    public function revokeSession(int $sessionId, string $reason, DateTimeImmutable $now): void
    {
        $this->execute(<<<'SQL'
UPDATE pa_platform_session
SET status = 'revoked', revoked_at = :revoked_at, revoke_reason = :reason, updated_at = :updated_at
WHERE id = :session_id AND status = 'active'
SQL, [
            'revoked_at' => $this->format($now),
            'reason' => $reason,
            'updated_at' => $this->format($now),
            'session_id' => $sessionId,
        ]);
        $this->execute(<<<'SQL'
UPDATE pa_platform_session_token SET status = 'revoked', revoked_at = :revoked_at
WHERE session_id = :session_id AND status = 'active'
SQL, ['revoked_at' => $this->format($now), 'session_id' => $sessionId]);
    }

    public function recordEvent(
        string $eventType,
        string $outcome,
        ?string $reasonCode,
        ?int $accountId,
        ?int $credentialId,
        ?string $sessionKey,
        ?string $identifierHmac,
        string $requestId,
        string $ipAddress,
        ?string $userAgentHash,
        DateTimeImmutable $now,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_auth_security_event (
    audience, event_type, outcome, reason_code,
    account_id, credential_id, session_key, identifier_hmac,
    request_id, ip_address, user_agent_hash, occurred_at
) VALUES (
    'platform', :event_type, :outcome, :reason_code,
    :account_id, :credential_id, :session_key, :identifier_hmac,
    :request_id, :ip_address, :user_agent_hash, :occurred_at
)
SQL, [
            'event_type' => $eventType,
            'outcome' => $outcome,
            'reason_code' => $reasonCode,
            'account_id' => $accountId,
            'credential_id' => $credentialId,
            'session_key' => $sessionKey,
            'identifier_hmac' => $identifierHmac,
            'request_id' => $requestId,
            'ip_address' => $ipAddress,
            'user_agent_hash' => $userAgentHash,
            'occurred_at' => $this->format($now),
        ]);
    }

    private function insertToken(
        int $sessionId,
        string $type,
        string $hash,
        DateTimeImmutable $expiresAt,
        ?int $parentTokenId,
        DateTimeImmutable $now,
    ): int {
        $this->execute(<<<'SQL'
INSERT INTO pa_platform_session_token (
    session_id, token_type, token_hash, parent_token_id, expires_at, created_at
) VALUES (
    :session_id, :token_type, :token_hash, :parent_token_id, :expires_at, :created_at
)
SQL, [
            'session_id' => $sessionId,
            'token_type' => $type,
            'token_hash' => $hash,
            'parent_token_id' => $parentTokenId,
            'expires_at' => $this->format($expiresAt),
            'created_at' => $this->format($now),
        ]);

        return $this->lastInsertId();
    }

    private function format(DateTimeImmutable $time): string
    {
        return $time->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        return is_string($value) ? $this->date($value) : null;
    }
}
