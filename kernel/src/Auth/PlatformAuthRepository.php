<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Identity\EmailAddress;

interface PlatformAuthRepository
{
    public function principalByEmail(EmailAddress $email, bool $forUpdate = false): ?PlatformAuthPrincipal;

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
        string $requestId,
        string $ipAddress,
        ?string $userAgentHash,
        DateTimeImmutable $now,
    ): void;
}
