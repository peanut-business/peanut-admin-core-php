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
use PeanutAdmin\Kernel\Persistence\Model\Account;
use PeanutAdmin\Kernel\Persistence\Model\AuthSecurityEvent;
use PeanutAdmin\Kernel\Persistence\Model\Credential;
use PeanutAdmin\Kernel\Persistence\Model\LoginChallenge;
use PeanutAdmin\Kernel\Persistence\Model\TenantMember;
use PeanutAdmin\Kernel\Persistence\Model\TenantSession;
use PeanutAdmin\Kernel\Persistence\Model\TenantSessionToken;
use PeanutAdmin\Kernel\Tenancy\TenantStatus;
use think\db\Raw;

final class ThinkPhpTenantAuthRepository implements TenantAuthRepository
{
    public function failedLoginCountByIp(string $ipAddress, DateTimeImmutable $since): int
    {
        return (int) AuthSecurityEvent::where('event_type', 'login_failed')
            ->where('outcome', 'denied')->where('ip_address', $ipAddress)
            ->where('occurred_at', '>=', $this->format($since))->count();
    }

    public function failedLoginCountByIdentifier(string $identifierHmac, DateTimeImmutable $since): int
    {
        return (int) AuthSecurityEvent::where('event_type', 'login_failed')
            ->where('outcome', 'denied')->where('identifier_hmac', $identifierHmac)
            ->where('occurred_at', '>=', $this->format($since))->count();
    }

    public function credentialByEmail(EmailAddress $email, bool $forUpdate = false): ?AuthCredential
    {
        $query = Credential::alias('credential')
            ->join('account account', 'account.id = credential.account_id')
            ->where('credential.identifier_type', 'email')
            ->where('credential.identifier_normalized', $email->value())
            ->field([
                'credential.id' => 'credential_id', 'credential.account_id', 'credential.secret_hash',
                'credential.status' => 'credential_status', 'credential.failed_attempts',
                'credential.locked_until', 'credential.expires_at', 'account.status' => 'account_status',
            ]);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->find();

        return $row === null ? null : $this->credentialRecord($row->toArray());
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
                $this->recordFailedLoginEvent($credential, $identifierHmac, $ipAddress, $userAgentHash, $requestId, $now);
                return;
            }
            $attempts = $credential->credentialStatus === CredentialStatus::Locked
                ? 1
                : $credential->failedAttempts + 1;
            $lockedUntil = $attempts >= 5 ? $now->modify('+15 minutes') : null;
            $credentialLocked = $lockedUntil !== null;
            Credential::where('id', $credential->credentialId)->update([
                'failed_attempts' => $attempts,
                'status' => $credentialLocked ? 'locked' : 'active',
                'locked_until' => $lockedUntil === null ? null : $this->format($lockedUntil),
                'revision' => new Raw('revision + 1'),
                'updated_at' => $this->format($now),
            ]);
            if ($credentialLocked) {
                Account::where('id', $credential->accountId)->update([
                    'security_revision' => new Raw('security_revision + 1'),
                    'updated_at' => $this->format($now),
                ]);
            }
        }
        $this->recordFailedLoginEvent($credential, $identifierHmac, $ipAddress, $userAgentHash, $requestId, $now);
        if ($credentialLocked && $credential !== null) {
            $this->recordSecurityEvent(
                'credential_locked', 'denied', 'failed_attempt_limit',
                $credential->accountId, $credential->credentialId, null,
                $identifierHmac, $requestId, $ipAddress, $userAgentHash, $now,
            );
        }
    }

    public function registerSuccessfulLogin(
        AuthCredential $credential,
        ?string $replacementSecretHash,
        DateTimeImmutable $now,
    ): void {
        $data = [
            'status' => 'active',
            'failed_attempts' => 0,
            'locked_until' => null,
            'revision' => new Raw('revision + 1'),
            'last_used_at' => $this->format($now),
            'updated_at' => $this->format($now),
        ];
        if ($replacementSecretHash !== null) {
            $data['secret_hash'] = $replacementSecretHash;
            $data['secret_changed_at'] = $this->format($now);
        }
        Credential::where('id', $credential->credentialId)->update($data);
        Account::where('id', $credential->accountId)->update([
            'last_login_at' => $this->format($now),
            'updated_at' => $this->format($now),
        ]);
    }

    public function availableTenants(int $accountId, ?string $tenantCode = null): array
    {
        $query = TenantMember::alias('member')
            ->join('tenant tenant', 'tenant.id = member.tenant_id')
            ->join('account account', 'account.id = member.account_id')
            ->where('member.account_id', $accountId)
            ->where('member.status', 'active')
            ->where('tenant.status', 'active')
            ->field([
                'tenant.id' => 'tenant_id', 'tenant.code' => 'tenant_code',
                'tenant.display_name' => 'tenant_name', 'member.id' => 'member_id',
                'member.display_name' => 'member_display_name',
                'account.display_name' => 'account_display_name',
            ])->order('tenant.id');
        if ($tenantCode !== null) {
            $query->where('tenant.code', $tenantCode);
        }

        return array_values(array_map(static fn(array $row): TenantChoice => new TenantChoice(
            (int) $row['tenant_id'],
            (string) $row['tenant_code'],
            (string) $row['tenant_name'],
            (int) $row['member_id'],
            (string) ($row['member_display_name'] ?? $row['account_display_name']),
        ), $query->select()->toArray()));
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
        LoginChallenge::insert([
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
        $query = LoginChallenge::where('token_hash', $tokenHash)->field(
            'id,account_id,client_key,purpose,status,source_session_key,ip_address,user_agent_hash,expires_at',
        );
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->find();
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
        if (LoginChallenge::where('id', $challengeId)->where('status', 'active')->update([
            'status' => 'used',
            'used_at' => $this->format($now),
        ]) !== 1) {
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
        $principal = TenantMember::alias('member')
            ->join('account account', 'account.id = member.account_id')
            ->join('tenant tenant', 'tenant.id = member.tenant_id')
            ->where('member.tenant_id', $choice->tenantId)
            ->where('member.id', $choice->memberId)
            ->where('member.status', 'active')->where('account.status', 'active')->where('tenant.status', 'active')
            ->field([
                'member.account_id', 'member.security_revision' => 'member_security_revision',
                'member.authorization_revision', 'account.security_revision' => 'account_security_revision',
                'tenant.security_revision' => 'tenant_security_revision',
            ])->lock(true)->find();
        if ($principal === null) {
            throw new DomainException('Tenant session principal is unavailable.');
        }
        $idleExpiresAt = min($now->modify('+8 hours'), $tokens->refreshExpiresAt);
        $sessionId = (int) TenantSession::insertGetId([
            'session_key' => $sessionKey,
            'tenant_id' => $choice->tenantId,
            'account_id' => (int) $principal['account_id'],
            'tenant_member_id' => $choice->memberId,
            'client_key' => $clientKey,
            'account_security_revision' => (int) $principal['account_security_revision'],
            'tenant_security_revision' => (int) $principal['tenant_security_revision'],
            'member_security_revision' => (int) $principal['member_security_revision'],
            'issued_at' => $this->format($now),
            'last_seen_at' => $this->format($now),
            'idle_expires_at' => $this->format($idleExpiresAt),
            'absolute_expires_at' => $this->format($tokens->refreshExpiresAt),
            'ip_address' => $ipAddress,
            'user_agent_hash' => $userAgentHash,
            'created_at' => $this->format($now),
            'updated_at' => $this->format($now),
        ]);
        $this->insertToken($sessionId, 'access', $tokens->access->hash(), $tokens->accessExpiresAt, null, $now);
        $this->insertToken($sessionId, 'refresh', $tokens->refresh->hash(), $tokens->refreshExpiresAt, null, $now);

        return new ValidatedTenantSession(
            $sessionId, $sessionKey, $choice->tenantId, (int) $principal['account_id'],
            $choice->memberId, $clientKey, $now, (int) $principal['authorization_revision'],
        );
    }

    public function sessionByTokenHash(
        string $tokenHash,
        string $tokenType,
        bool $forUpdate = false,
    ): ?SessionAuthenticationRecord {
        $query = TenantSessionToken::alias('token')
            ->join('tenant_session session', 'session.id = token.session_id')
            ->join('account account', 'account.id = session.account_id')
            ->join('tenant tenant', 'tenant.id = session.tenant_id')
            ->join(
                'tenant_member member',
                'member.tenant_id = session.tenant_id AND member.id = session.tenant_member_id AND member.account_id = session.account_id',
            )
            ->where('token.token_hash', $tokenHash)->where('token.token_type', $tokenType)
            ->field([
                'token.id' => 'token_id', 'token.token_type', 'token.status' => 'token_status',
                'token.expires_at' => 'token_expires_at', 'session.id' => 'session_id',
                'session.session_key', 'session.status' => 'session_status', 'session.tenant_id',
                'session.account_id', 'session.tenant_member_id', 'session.client_key', 'session.issued_at',
                'session.idle_expires_at', 'session.absolute_expires_at', 'session.account_security_revision',
                'session.tenant_security_revision', 'session.member_security_revision',
                'account.status' => 'account_status', 'account.security_revision' => 'current_account_security_revision',
                'tenant.status' => 'tenant_status', 'tenant.security_revision' => 'current_tenant_security_revision',
                'member.status' => 'member_status', 'member.security_revision' => 'current_member_security_revision',
                'member.authorization_revision',
            ]);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->find();

        return $row === null ? null : $this->sessionRecord($row->toArray());
    }

    public function rotateTokens(
        SessionAuthenticationRecord $refresh,
        TenantTokenPair $tokens,
        DateTimeImmutable $now,
    ): void {
        TenantSessionToken::where('id', $refresh->tokenId)->where('status', 'active')->update([
            'status' => 'used', 'used_at' => $this->format($now),
        ]);
        TenantSessionToken::where('session_id', $refresh->sessionId)
            ->where('token_type', 'access')->where('status', 'active')->update([
                'status' => 'revoked', 'revoked_at' => $this->format($now),
            ]);
        $this->insertToken($refresh->sessionId, 'access', $tokens->access->hash(), $tokens->accessExpiresAt, null, $now);
        $newRefreshId = $this->insertToken(
            $refresh->sessionId, 'refresh', $tokens->refresh->hash(), $tokens->refreshExpiresAt, $refresh->tokenId, $now,
        );
        TenantSessionToken::where('id', $refresh->tokenId)->update([
            'replaced_by_token_id' => $newRefreshId,
        ]);
        TenantSession::where('id', $refresh->sessionId)->update([
            'last_seen_at' => $this->format($now),
            'idle_expires_at' => $this->format(min($now->modify('+8 hours'), $refresh->absoluteExpiresAt)),
            'updated_at' => $this->format($now),
        ]);
    }

    public function revokeSession(int $sessionId, string $reason, DateTimeImmutable $now): void
    {
        TenantSession::where('id', $sessionId)->where('status', 'active')->update([
            'status' => 'revoked', 'revoked_at' => $this->format($now),
            'revoke_reason' => $reason, 'updated_at' => $this->format($now),
        ]);
        $this->revokeTokensForSession($sessionId, $now);
    }

    public function revokeSessionsForAccount(int $accountId, string $reason, DateTimeImmutable $now): void
    {
        $sessionIds = TenantSession::where('account_id', $accountId)
            ->where('status', 'active')->lock(true)->column('id');
        foreach ($sessionIds as $sessionId) {
            $this->revokeSession((int) $sessionId, $reason, $now);
        }
    }

    public function revokeSessionByKey(string $sessionKey, string $reason, DateTimeImmutable $now): void
    {
        $id = TenantSession::where('session_key', $sessionKey)->lock(true)->value('id');
        if ($id !== null) {
            $this->revokeSession((int) $id, $reason, $now);
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
        AuthSecurityEvent::insert([
            'audience' => 'tenant', 'event_type' => $eventType, 'outcome' => $outcome,
            'reason_code' => $reasonCode, 'account_id' => $accountId, 'credential_id' => $credentialId,
            'session_key' => $sessionKey, 'identifier_hmac' => $identifierHmac, 'request_id' => $requestId,
            'ip_address' => $ipAddress, 'user_agent_hash' => $userAgentHash, 'occurred_at' => $this->format($now),
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
            'login_failed', 'denied', 'invalid_credentials', $credential?->accountId,
            $credential?->credentialId, null, $identifierHmac, $requestId, $ipAddress, $userAgentHash, $now,
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
        return (int) TenantSessionToken::insertGetId([
            'session_id' => $sessionId,
            'token_type' => $type,
            'token_hash' => $hash,
            'parent_token_id' => $parentTokenId,
            'expires_at' => $this->format($expiresAt),
            'created_at' => $this->format($now),
        ]);
    }

    private function revokeTokensForSession(int $sessionId, DateTimeImmutable $now): void
    {
        TenantSessionToken::where('session_id', $sessionId)->where('status', 'active')->update([
            'status' => 'revoked', 'revoked_at' => $this->format($now),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function credentialRecord(array $row): AuthCredential
    {
        return new AuthCredential(
            (int) $row['credential_id'], (int) $row['account_id'], (string) $row['secret_hash'],
            CredentialStatus::from((string) $row['credential_status']), (int) $row['failed_attempts'],
            $this->nullableDate($row['locked_until']), $this->nullableDate($row['expires_at']),
            AccountStatus::from((string) $row['account_status']),
        );
    }

    /** @param array<string, mixed> $row */
    private function sessionRecord(array $row): SessionAuthenticationRecord
    {
        return new SessionAuthenticationRecord(
            (int) $row['token_id'], (string) $row['token_type'], (string) $row['token_status'],
            $this->date((string) $row['token_expires_at']), (int) $row['session_id'],
            (string) $row['session_key'], (string) $row['session_status'], (int) $row['tenant_id'],
            (int) $row['account_id'], (int) $row['tenant_member_id'], (string) $row['client_key'],
            $this->date((string) $row['issued_at']), $this->date((string) $row['idle_expires_at']),
            $this->date((string) $row['absolute_expires_at']), (int) $row['account_security_revision'],
            (int) $row['tenant_security_revision'], (int) $row['member_security_revision'],
            AccountStatus::from((string) $row['account_status']), (int) $row['current_account_security_revision'],
            TenantStatus::from((string) $row['tenant_status']), (int) $row['current_tenant_security_revision'],
            TenantMemberStatus::from((string) $row['member_status']), (int) $row['current_member_security_revision'],
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
