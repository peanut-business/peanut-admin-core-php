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
use PeanutAdmin\Kernel\Platform\PlatformOperatorStatus;
use PeanutAdmin\Kernel\Persistence\Model\Account;
use PeanutAdmin\Kernel\Persistence\Model\AuthSecurityEvent;
use PeanutAdmin\Kernel\Persistence\Model\Credential;
use PeanutAdmin\Kernel\Persistence\Model\PlatformSession;
use PeanutAdmin\Kernel\Persistence\Model\PlatformSessionToken;
use think\db\Raw;

final class ThinkPhpPlatformAuthRepository implements PlatformAuthRepository
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

    public function principalByEmail(EmailAddress $email, bool $forUpdate = false): ?PlatformAuthPrincipal
    {
        $query = Credential::alias('credential')
            ->join('account account', 'account.id = credential.account_id')
            ->join('platform_operator operator', 'operator.account_id = account.id')
            ->where('credential.identifier_type', 'email')
            ->where('credential.identifier_normalized', $email->value())
            ->field([
                'credential.id' => 'credential_id', 'credential.account_id', 'credential.secret_hash',
                'credential.status' => 'credential_status', 'credential.failed_attempts',
                'credential.locked_until', 'credential.expires_at', 'account.status' => 'account_status',
                'account.security_revision' => 'account_security_revision', 'operator.id' => 'operator_id',
                'operator.status' => 'operator_status', 'operator.security_revision' => 'operator_security_revision',
            ]);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->find();
        if ($row === null) {
            return null;
        }

        return new PlatformAuthPrincipal(
            (int) $row['credential_id'], (int) $row['account_id'], (string) $row['secret_hash'],
            CredentialStatus::from((string) $row['credential_status']), (int) $row['failed_attempts'],
            $this->nullableDate($row['locked_until']), $this->nullableDate($row['expires_at']),
            AccountStatus::from((string) $row['account_status']), (int) $row['account_security_revision'],
            (int) $row['operator_id'], PlatformOperatorStatus::from((string) $row['operator_status']),
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
                Credential::where('id', $principal->credentialId)->update([
                    'failed_attempts' => $attempts,
                    'status' => $credentialLocked ? 'locked' : 'active',
                    'locked_until' => $lockedUntil === null ? null : $this->format($lockedUntil),
                    'revision' => new Raw('revision + 1'),
                    'updated_at' => $this->format($now),
                ]);
                if ($credentialLocked) {
                    Account::where('id', $principal->accountId)->update([
                        'security_revision' => new Raw('security_revision + 1'),
                        'updated_at' => $this->format($now),
                    ]);
                }
            }
        }
        $this->recordEvent(
            'login_failed', 'denied', 'invalid_credentials', $principal?->accountId,
            $principal?->credentialId, null, $identifierHmac, $requestId, $ipAddress, $userAgentHash, $now,
        );
        if ($credentialLocked && $principal !== null) {
            $this->recordEvent(
                'credential_locked', 'denied', 'failed_attempt_limit', $principal->accountId,
                $principal->credentialId, null, $identifierHmac, $requestId, $ipAddress, $userAgentHash, $now,
            );
        }
    }

    public function registerSuccessfulLogin(
        PlatformAuthPrincipal $principal,
        ?string $replacementSecretHash,
        DateTimeImmutable $now,
    ): void {
        $data = [
            'status' => 'active', 'failed_attempts' => 0, 'locked_until' => null,
            'revision' => new Raw('revision + 1'), 'last_used_at' => $this->format($now),
            'updated_at' => $this->format($now),
        ];
        if ($replacementSecretHash !== null) {
            $data['secret_hash'] = $replacementSecretHash;
            $data['secret_changed_at'] = $this->format($now);
        }
        Credential::where('id', $principal->credentialId)->update($data);
        Account::where('id', $principal->accountId)->update([
            'last_login_at' => $this->format($now), 'updated_at' => $this->format($now),
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
        $sessionId = (int) PlatformSession::insertGetId([
            'session_key' => $sessionKey,
            'account_id' => $principal->accountId,
            'platform_operator_id' => $principal->operatorId,
            'client_key' => 'platform-web',
            'account_security_revision' => $principal->accountSecurityRevision,
            'operator_security_revision' => $principal->operatorSecurityRevision,
            'issued_at' => $this->format($now),
            'last_seen_at' => $this->format($now),
            'idle_expires_at' => $this->format(min($now->modify('+8 hours'), $tokens->refreshExpiresAt)),
            'absolute_expires_at' => $this->format($tokens->refreshExpiresAt),
            'ip_address' => $ipAddress,
            'user_agent_hash' => $userAgentHash,
            'created_at' => $this->format($now),
            'updated_at' => $this->format($now),
        ]);
        $this->insertToken($sessionId, 'access', $tokens->access->hash(), $tokens->accessExpiresAt, null, $now);
        $this->insertToken($sessionId, 'refresh', $tokens->refresh->hash(), $tokens->refreshExpiresAt, null, $now);

        return new ValidatedPlatformSession(
            $sessionId, $sessionKey, $principal->accountId, $principal->operatorId, 'platform-web', $now,
        );
    }

    public function sessionByTokenHash(
        string $tokenHash,
        string $tokenType,
        bool $forUpdate = false,
    ): ?PlatformSessionAuthenticationRecord {
        $query = PlatformSessionToken::alias('token')
            ->join('platform_session session', 'session.id = token.session_id')
            ->join('account account', 'account.id = session.account_id')
            ->join(
                'platform_operator operator',
                'operator.id = session.platform_operator_id AND operator.account_id = session.account_id',
            )
            ->where('token.token_hash', $tokenHash)->where('token.token_type', $tokenType)
            ->field([
                'token.id' => 'token_id', 'token.token_type', 'token.status' => 'token_status',
                'token.expires_at' => 'token_expires_at', 'session.id' => 'session_id',
                'session.session_key', 'session.status' => 'session_status', 'session.account_id',
                'session.platform_operator_id', 'session.client_key', 'session.issued_at',
                'session.idle_expires_at', 'session.absolute_expires_at', 'session.account_security_revision',
                'session.operator_security_revision', 'account.status' => 'account_status',
                'account.security_revision' => 'current_account_security_revision',
                'operator.status' => 'operator_status',
                'operator.security_revision' => 'current_operator_security_revision',
            ]);
        if ($forUpdate) {
            $query->lock(true);
        }
        $row = $query->find();
        if ($row === null) {
            return null;
        }

        return new PlatformSessionAuthenticationRecord(
            (int) $row['token_id'], (string) $row['token_type'], (string) $row['token_status'],
            $this->date((string) $row['token_expires_at']), (int) $row['session_id'],
            (string) $row['session_key'], (string) $row['session_status'], (int) $row['account_id'],
            (int) $row['platform_operator_id'], (string) $row['client_key'],
            $this->date((string) $row['issued_at']), $this->date((string) $row['idle_expires_at']),
            $this->date((string) $row['absolute_expires_at']), (int) $row['account_security_revision'],
            (int) $row['operator_security_revision'], AccountStatus::from((string) $row['account_status']),
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
        PlatformSessionToken::where('id', $refresh->tokenId)->where('status', 'active')->update([
            'status' => 'used', 'used_at' => $this->format($now),
        ]);
        PlatformSessionToken::where('session_id', $refresh->sessionId)
            ->where('token_type', 'access')->where('status', 'active')->update([
                'status' => 'revoked', 'revoked_at' => $this->format($now),
            ]);
        $this->insertToken($refresh->sessionId, 'access', $tokens->access->hash(), $tokens->accessExpiresAt, null, $now);
        $newRefreshId = $this->insertToken(
            $refresh->sessionId, 'refresh', $tokens->refresh->hash(), $tokens->refreshExpiresAt, $refresh->tokenId, $now,
        );
        PlatformSessionToken::where('id', $refresh->tokenId)->update([
            'replaced_by_token_id' => $newRefreshId,
        ]);
        PlatformSession::where('id', $refresh->sessionId)->update([
            'last_seen_at' => $this->format($now),
            'idle_expires_at' => $this->format(min($now->modify('+8 hours'), $refresh->absoluteExpiresAt)),
            'updated_at' => $this->format($now),
        ]);
    }

    public function revokeSession(int $sessionId, string $reason, DateTimeImmutable $now): void
    {
        PlatformSession::where('id', $sessionId)->where('status', 'active')->update([
            'status' => 'revoked', 'revoked_at' => $this->format($now),
            'revoke_reason' => $reason, 'updated_at' => $this->format($now),
        ]);
        PlatformSessionToken::where('session_id', $sessionId)->where('status', 'active')->update([
            'status' => 'revoked', 'revoked_at' => $this->format($now),
        ]);
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
        AuthSecurityEvent::insert([
            'audience' => 'platform', 'event_type' => $eventType, 'outcome' => $outcome,
            'reason_code' => $reasonCode, 'account_id' => $accountId, 'credential_id' => $credentialId,
            'session_key' => $sessionKey, 'identifier_hmac' => $identifierHmac, 'request_id' => $requestId,
            'ip_address' => $ipAddress, 'user_agent_hash' => $userAgentHash, 'occurred_at' => $this->format($now),
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
        return (int) PlatformSessionToken::insertGetId([
            'session_id' => $sessionId, 'token_type' => $type, 'token_hash' => $hash,
            'parent_token_id' => $parentTokenId, 'expires_at' => $this->format($expiresAt),
            'created_at' => $this->format($now),
        ]);
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
