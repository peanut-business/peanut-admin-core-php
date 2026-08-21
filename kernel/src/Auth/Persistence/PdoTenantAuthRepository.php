<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use PeanutAdmin\Kernel\Auth\AuthCredential;
use PeanutAdmin\Kernel\Auth\LoginChallengeRecord;
use PeanutAdmin\Kernel\Auth\SessionAuthenticationRecord;
use PeanutAdmin\Kernel\Auth\TenantAuthRepository;
use PeanutAdmin\Kernel\Auth\TenantChoice;
use PeanutAdmin\Kernel\Auth\TenantTokenPair;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Identity\AccountStatus;
use PeanutAdmin\Kernel\Identity\CredentialStatus;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Membership\TenantMemberStatus;
use PeanutAdmin\Kernel\Persistence\Pdo\PdoRepository;
use PeanutAdmin\Kernel\Tenancy\TenantStatus;

final class PdoTenantAuthRepository extends PdoRepository implements TenantAuthRepository
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

    public function credentialByEmail(EmailAddress $email, bool $forUpdate = false): ?AuthCredential
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
    a.status AS account_status
FROM pa_credential c
JOIN pa_account a ON a.id = c.account_id
WHERE c.identifier_type = 'email' AND c.identifier_normalized = :email
SQL . ($forUpdate ? ' FOR UPDATE' : ''),
            ['email' => $email->value()],
        );

        return $row === null ? null : $this->credentialRecord($row);
    }

    public function registerFailedLogin(
        ?AuthCredential $credential,
        string $identifierHmac,
        string $ipAddress,
        ?string $userAgentHash,
        string $requestId,
        DateTimeImmutable $now,
    ): void {
        $credentialLocked = false;
        if ($credential !== null) {
            $lockIsActive = $credential->credentialStatus === CredentialStatus::Locked
                && $credential->lockedUntil !== null
                && $now < $credential->lockedUntil;
            if ($lockIsActive) {
                $this->recordFailedLoginEvent(
                    $credential,
                    $identifierHmac,
                    $ipAddress,
                    $userAgentHash,
                    $requestId,
                    $now,
                );

                return;
            }

            $attempts = $credential->credentialStatus === CredentialStatus::Locked
                ? 1
                : $credential->failedAttempts + 1;
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
                'credential_id' => $credential->credentialId,
            ]);
            if ($credentialLocked) {
                $this->execute(<<<'SQL'
UPDATE pa_account
SET security_revision = security_revision + 1, updated_at = :updated_at
WHERE id = :account_id
SQL, [
                    'updated_at' => $this->format($now),
                    'account_id' => $credential->accountId,
                ]);
            }
        }

        $this->recordFailedLoginEvent(
            $credential,
            $identifierHmac,
            $ipAddress,
            $userAgentHash,
            $requestId,
            $now,
        );
        if ($credentialLocked && $credential !== null) {
            $this->recordSecurityEvent(
                'credential_locked',
                'denied',
                'failed_attempt_limit',
                $credential->accountId,
                $credential->credentialId,
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
        AuthCredential $credential,
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
            'credential_id' => $credential->credentialId,
        ]);
        $this->execute(<<<'SQL'
UPDATE pa_account SET last_login_at = :last_login_at, updated_at = :updated_at WHERE id = :account_id
SQL, [
            'last_login_at' => $this->format($now),
            'updated_at' => $this->format($now),
            'account_id' => $credential->accountId,
        ]);
    }

    public function availableTenants(int $accountId, ?string $tenantCode = null): array
    {
        $parameters = ['account_id' => $accountId];
        $tenantPredicate = '';
        if ($tenantCode !== null) {
            $tenantPredicate = ' AND t.code = :tenant_code';
            $parameters['tenant_code'] = $tenantCode;
        }

        $statement = $this->pdo->prepare(<<<SQL
SELECT
    t.id AS tenant_id,
    t.code AS tenant_code,
    t.display_name AS tenant_name,
    tm.id AS member_id,
    COALESCE(tm.display_name, a.display_name) AS member_display_name
FROM pa_tenant_member tm
JOIN pa_tenant t ON t.id = tm.tenant_id
JOIN pa_account a ON a.id = tm.account_id
WHERE tm.account_id = :account_id
  AND tm.status = 'active'
  AND t.status = 'active'
  {$tenantPredicate}
ORDER BY t.id
SQL);
        if ($statement === false) {
            throw new DomainException('Could not query available tenants.');
        }
        $statement->execute($parameters);

        $choices = [];
        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $choices[] = new TenantChoice(
                (int) $row['tenant_id'],
                (string) $row['tenant_code'],
                (string) $row['tenant_name'],
                (int) $row['member_id'],
                (string) $row['member_display_name'],
            );
        }

        return $choices;
    }

    public function createChallenge(
        int $accountId,
        string $challengeKey,
        string $tokenHash,
        string $purpose,
        string $clientKey,
        ?string $sourceSessionKey,
        ?string $ipAddress,
        ?string $userAgentHash,
        DateTimeImmutable $expiresAt,
        DateTimeImmutable $now,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_login_challenge (
    challenge_key, token_hash, account_id, purpose, client_key, source_session_key,
    ip_address, user_agent_hash, expires_at, created_at
) VALUES (
    :challenge_key, :token_hash, :account_id, :purpose, :client_key, :source_session_key,
    :ip_address, :user_agent_hash, :expires_at, :created_at
)
SQL, [
            'challenge_key' => $challengeKey,
            'token_hash' => $tokenHash,
            'account_id' => $accountId,
            'purpose' => $purpose,
            'client_key' => $clientKey,
            'source_session_key' => $sourceSessionKey,
            'ip_address' => $ipAddress,
            'user_agent_hash' => $userAgentHash,
            'expires_at' => $this->format($expiresAt),
            'created_at' => $this->format($now),
        ]);
    }

    public function challengeByHash(string $tokenHash, bool $forUpdate = false): ?LoginChallengeRecord
    {
        $row = $this->fetchOne(
            'SELECT id, account_id, client_key, purpose, status, source_session_key, ip_address, user_agent_hash, expires_at'
            . ' FROM pa_login_challenge WHERE token_hash = :token_hash'
            . ($forUpdate ? ' FOR UPDATE' : ''),
            ['token_hash' => $tokenHash],
        );
        if ($row === null) {
            return null;
        }

        return new LoginChallengeRecord(
            (int) $row['id'],
            (int) $row['account_id'],
            (string) $row['client_key'],
            (string) $row['purpose'],
            (string) $row['status'],
            is_string($row['source_session_key']) ? $row['source_session_key'] : null,
            is_string($row['ip_address']) ? $row['ip_address'] : null,
            is_string($row['user_agent_hash']) ? $row['user_agent_hash'] : null,
            $this->date((string) $row['expires_at']),
        );
    }

    public function markChallengeUsed(int $challengeId, DateTimeImmutable $now): void
    {
        $affected = $this->execute(<<<'SQL'
UPDATE pa_login_challenge SET status = 'used', used_at = :used_at
WHERE id = :id AND status = 'active'
SQL, ['used_at' => $this->format($now), 'id' => $challengeId]);
        if ($affected !== 1) {
            throw new DomainException('Challenge state changed concurrently.');
        }
    }

    public function createSession(
        TenantChoice $choice,
        string $sessionKey,
        TenantTokenPair $tokens,
        string $clientKey,
        string $ipAddress,
        ?string $userAgentHash,
        DateTimeImmutable $now,
    ): ValidatedTenantSession {
        $principal = $this->fetchOne(<<<'SQL'
SELECT
    tm.account_id,
    tm.security_revision AS member_security_revision,
    tm.authorization_revision,
    a.security_revision AS account_security_revision,
    t.security_revision AS tenant_security_revision
FROM pa_tenant_member tm
JOIN pa_account a ON a.id = tm.account_id
JOIN pa_tenant t ON t.id = tm.tenant_id
WHERE tm.tenant_id = :tenant_id AND tm.id = :member_id
  AND tm.status = 'active' AND a.status = 'active' AND t.status = 'active'
FOR UPDATE
SQL, ['tenant_id' => $choice->tenantId, 'member_id' => $choice->memberId]);
        if ($principal === null) {
            throw new DomainException('Tenant session principal is unavailable.');
        }

        $idleExpiresAt = min($now->modify('+8 hours'), $tokens->refreshExpiresAt);
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant_session (
    session_key, tenant_id, account_id, tenant_member_id, client_key,
    account_security_revision, tenant_security_revision, member_security_revision,
    issued_at, last_seen_at, idle_expires_at, absolute_expires_at,
    ip_address, user_agent_hash, created_at, updated_at
) VALUES (
    :session_key, :tenant_id, :account_id, :member_id, :client_key,
    :account_revision, :tenant_revision, :member_revision,
    :issued_at, :last_seen_at, :idle_expires_at, :absolute_expires_at,
    :ip_address, :user_agent_hash, :created_at, :updated_at
)
SQL, [
            'session_key' => $sessionKey,
            'tenant_id' => $choice->tenantId,
            'account_id' => (int) $principal['account_id'],
            'member_id' => $choice->memberId,
            'client_key' => $clientKey,
            'account_revision' => (int) $principal['account_security_revision'],
            'tenant_revision' => (int) $principal['tenant_security_revision'],
            'member_revision' => (int) $principal['member_security_revision'],
            'issued_at' => $this->format($now),
            'last_seen_at' => $this->format($now),
            'idle_expires_at' => $this->format($idleExpiresAt),
            'absolute_expires_at' => $this->format($tokens->refreshExpiresAt),
            'ip_address' => $ipAddress,
            'user_agent_hash' => $userAgentHash,
            'created_at' => $this->format($now),
            'updated_at' => $this->format($now),
        ]);
        $sessionId = $this->lastInsertId();
        $this->insertToken($sessionId, 'access', $tokens->access->hash(), $tokens->accessExpiresAt, null, $now);
        $this->insertToken($sessionId, 'refresh', $tokens->refresh->hash(), $tokens->refreshExpiresAt, null, $now);

        return new ValidatedTenantSession(
            $sessionId,
            $sessionKey,
            $choice->tenantId,
            (int) $principal['account_id'],
            $choice->memberId,
            $clientKey,
            $now,
            (int) $principal['authorization_revision'],
        );
    }

    public function sessionByTokenHash(
        string $tokenHash,
        string $tokenType,
        bool $forUpdate = false,
    ): ?SessionAuthenticationRecord {
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
    s.tenant_id,
    s.account_id,
    s.tenant_member_id,
    s.client_key,
    s.issued_at,
    s.idle_expires_at,
    s.absolute_expires_at,
    s.account_security_revision,
    s.tenant_security_revision,
    s.member_security_revision,
    a.status AS account_status,
    a.security_revision AS current_account_security_revision,
    t.status AS tenant_status,
    t.security_revision AS current_tenant_security_revision,
    tm.status AS member_status,
    tm.security_revision AS current_member_security_revision,
    tm.authorization_revision
FROM pa_tenant_session_token st
JOIN pa_tenant_session s ON s.id = st.session_id
JOIN pa_account a ON a.id = s.account_id
JOIN pa_tenant t ON t.id = s.tenant_id
JOIN pa_tenant_member tm
  ON tm.tenant_id = s.tenant_id
 AND tm.id = s.tenant_member_id
 AND tm.account_id = s.account_id
WHERE st.token_hash = :token_hash AND st.token_type = :token_type
SQL . ($forUpdate ? ' FOR UPDATE' : ''),
            ['token_hash' => $tokenHash, 'token_type' => $tokenType],
        );

        return $row === null ? null : $this->sessionRecord($row);
    }

    public function rotateTokens(
        SessionAuthenticationRecord $refresh,
        TenantTokenPair $tokens,
        DateTimeImmutable $now,
    ): void {
        $this->execute(<<<'SQL'
UPDATE pa_tenant_session_token
SET status = 'used', used_at = :used_at
WHERE id = :token_id AND status = 'active'
SQL, ['used_at' => $this->format($now), 'token_id' => $refresh->tokenId]);
        $this->execute(<<<'SQL'
UPDATE pa_tenant_session_token
SET status = 'revoked', revoked_at = :revoked_at
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
UPDATE pa_tenant_session_token SET replaced_by_token_id = :replacement_id WHERE id = :token_id
SQL, ['replacement_id' => $newRefreshId, 'token_id' => $refresh->tokenId]);
        $this->execute(<<<'SQL'
UPDATE pa_tenant_session
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
UPDATE pa_tenant_session
SET status = 'revoked', revoked_at = :revoked_at, revoke_reason = :reason, updated_at = :updated_at
WHERE id = :session_id AND status = 'active'
SQL, [
            'revoked_at' => $this->format($now),
            'reason' => $reason,
            'updated_at' => $this->format($now),
            'session_id' => $sessionId,
        ]);
        $this->revokeTokensForSession($sessionId, $now);
    }

    public function revokeSessionsForAccount(int $accountId, string $reason, DateTimeImmutable $now): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id FROM pa_tenant_session WHERE account_id = :account_id AND status = 'active' FOR UPDATE
SQL);
        if ($statement === false) {
            throw new DomainException('Could not lock account sessions.');
        }
        $statement->execute(['account_id' => $accountId]);
        while (($sessionId = $statement->fetchColumn()) !== false) {
            $this->revokeSession((int) $sessionId, $reason, $now);
        }
    }

    public function revokeSessionByKey(string $sessionKey, string $reason, DateTimeImmutable $now): void
    {
        $row = $this->fetchOne(
            'SELECT id FROM pa_tenant_session WHERE session_key = :session_key FOR UPDATE',
            ['session_key' => $sessionKey],
        );
        if ($row !== null) {
            $this->revokeSession((int) $row['id'], $reason, $now);
        }
    }

    public function recordSecurityEvent(
        string $eventType,
        string $outcome,
        ?string $reasonCode,
        ?int $accountId,
        ?int $credentialId,
        ?string $sessionKey,
        ?string $identifierHmac,
        string $requestId,
        ?string $ipAddress,
        ?string $userAgentHash,
        DateTimeImmutable $now,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_auth_security_event (
    audience, event_type, outcome, reason_code,
    account_id, credential_id, session_key, identifier_hmac,
    request_id, ip_address, user_agent_hash, occurred_at
) VALUES (
    'tenant', :event_type, :outcome, :reason_code,
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

    private function recordFailedLoginEvent(
        ?AuthCredential $credential,
        string $identifierHmac,
        string $ipAddress,
        ?string $userAgentHash,
        string $requestId,
        DateTimeImmutable $now,
    ): void {
        $this->recordSecurityEvent(
            'login_failed',
            'denied',
            'invalid_credentials',
            $credential?->accountId,
            $credential?->credentialId,
            null,
            $identifierHmac,
            $requestId,
            $ipAddress,
            $userAgentHash,
            $now,
        );
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
INSERT INTO pa_tenant_session_token (
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

    private function revokeTokensForSession(int $sessionId, DateTimeImmutable $now): void
    {
        $this->execute(<<<'SQL'
UPDATE pa_tenant_session_token
SET status = 'revoked', revoked_at = :revoked_at
WHERE session_id = :session_id AND status = 'active'
SQL, ['revoked_at' => $this->format($now), 'session_id' => $sessionId]);
    }

    /** @param array<string, mixed> $row */
    private function credentialRecord(array $row): AuthCredential
    {
        return new AuthCredential(
            (int) $row['credential_id'],
            (int) $row['account_id'],
            (string) $row['secret_hash'],
            CredentialStatus::from((string) $row['credential_status']),
            (int) $row['failed_attempts'],
            $this->nullableDate($row['locked_until']),
            $this->nullableDate($row['expires_at']),
            AccountStatus::from((string) $row['account_status']),
        );
    }

    /** @param array<string, mixed> $row */
    private function sessionRecord(array $row): SessionAuthenticationRecord
    {
        return new SessionAuthenticationRecord(
            (int) $row['token_id'],
            (string) $row['token_type'],
            (string) $row['token_status'],
            $this->date((string) $row['token_expires_at']),
            (int) $row['session_id'],
            (string) $row['session_key'],
            (string) $row['session_status'],
            (int) $row['tenant_id'],
            (int) $row['account_id'],
            (int) $row['tenant_member_id'],
            (string) $row['client_key'],
            $this->date((string) $row['issued_at']),
            $this->date((string) $row['idle_expires_at']),
            $this->date((string) $row['absolute_expires_at']),
            (int) $row['account_security_revision'],
            (int) $row['tenant_security_revision'],
            (int) $row['member_security_revision'],
            AccountStatus::from((string) $row['account_status']),
            (int) $row['current_account_security_revision'],
            TenantStatus::from((string) $row['tenant_status']),
            (int) $row['current_tenant_security_revision'],
            TenantMemberStatus::from((string) $row['member_status']),
            (int) $row['current_member_security_revision'],
            (int) $row['authorization_revision'],
        );
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
