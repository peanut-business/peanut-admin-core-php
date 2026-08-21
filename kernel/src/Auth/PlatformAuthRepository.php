<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Identity\EmailAddress;

interface PlatformAuthRepository
{
    public function failedLoginCountByIp(string $ipAddress, DateTimeImmutable $since): int;

    public function failedLoginCountByIdentifier(string $identifierHmac, DateTimeImmutable $since): int;

    public function principalByEmail(EmailAddress $email, bool $forUpdate = false): ?PlatformAuthPrincipal;

    public function registerFailedLogin(
        ?PlatformAuthPrincipal $principal,
        string $identifierHmac,
        string $ipAddress,
        ?string $userAgentHash,
        string $requestId,
        DateTimeImmutable $now,
    ): void;

    public function registerSuccessfulLogin(
        PlatformAuthPrincipal $principal,
        ?string $replacementSecretHash,
        DateTimeImmutable $now,
    ): void;

    public function createSession(
        PlatformAuthPrincipal $principal,
        string $sessionKey,
        PlatformTokenPair $tokens,
        string $ipAddress,
        ?string $userAgentHash,
        DateTimeImmutable $now,
    ): ValidatedPlatformSession;

    public function sessionByTokenHash(
        string $tokenHash,
        string $tokenType,
        bool $forUpdate = false,
    ): ?PlatformSessionAuthenticationRecord;

    public function rotateTokens(
        PlatformSessionAuthenticationRecord $refresh,
        PlatformTokenPair $tokens,
        DateTimeImmutable $now,
    ): void;

    public function revokeSession(int $sessionId, string $reason, DateTimeImmutable $now): void;

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
    ): void;
}
