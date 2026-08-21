<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Identity\EmailAddress;

interface TenantAuthRepository
{
    public function failedLoginCountByIp(string $ipAddress, DateTimeImmutable $since): int;

    public function failedLoginCountByIdentifier(string $identifierHmac, DateTimeImmutable $since): int;

    public function credentialByEmail(EmailAddress $email, bool $forUpdate = false): ?AuthCredential;

    public function registerFailedLogin(
        ?AuthCredential $credential,
        string $identifierHmac,
        string $ipAddress,
        ?string $userAgentHash,
        string $requestId,
        DateTimeImmutable $now,
    ): void;

    public function registerSuccessfulLogin(
        AuthCredential $credential,
        ?string $replacementSecretHash,
        DateTimeImmutable $now,
    ): void;

    /** @return list<TenantChoice> */
    public function availableTenants(int $accountId, ?string $tenantCode = null): array;

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
    ): void;

    public function challengeByHash(string $tokenHash, bool $forUpdate = false): ?LoginChallengeRecord;

    public function markChallengeUsed(int $challengeId, DateTimeImmutable $now): void;

    public function createSession(
        TenantChoice $choice,
        string $sessionKey,
        TenantTokenPair $tokens,
        string $clientKey,
        string $ipAddress,
        ?string $userAgentHash,
        DateTimeImmutable $now,
    ): ValidatedTenantSession;

    public function sessionByTokenHash(
        string $tokenHash,
        string $tokenType,
        bool $forUpdate = false,
    ): ?SessionAuthenticationRecord;

    public function rotateTokens(
        SessionAuthenticationRecord $refresh,
        TenantTokenPair $tokens,
        DateTimeImmutable $now,
    ): void;

    public function revokeSession(int $sessionId, string $reason, DateTimeImmutable $now): void;

    public function revokeSessionsForAccount(int $accountId, string $reason, DateTimeImmutable $now): void;

    public function revokeSessionByKey(string $sessionKey, string $reason, DateTimeImmutable $now): void;

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
    ): void;
}
