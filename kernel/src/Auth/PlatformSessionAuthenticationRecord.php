<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Identity\AccountStatus;
use PeanutAdmin\Kernel\Platform\PlatformOperatorStatus;

final readonly class PlatformSessionAuthenticationRecord
{
    public function __construct(
        public int $tokenId,
        public string $tokenType,
        public string $tokenStatus,
        public DateTimeImmutable $tokenExpiresAt,
        public int $sessionId,
        public string $sessionKey,
        public string $sessionStatus,
        public int $accountId,
        public int $operatorId,
        public string $clientKey,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $idleExpiresAt,
        public DateTimeImmutable $absoluteExpiresAt,
        public int $accountSecurityRevision,
        public int $operatorSecurityRevision,
        public AccountStatus $accountStatus,
        public int $currentAccountSecurityRevision,
        public PlatformOperatorStatus $operatorStatus,
        public int $currentOperatorSecurityRevision,
    ) {}

    public function validated(): ValidatedPlatformSession
    {
        return new ValidatedPlatformSession(
            $this->sessionId,
            $this->sessionKey,
            $this->accountId,
            $this->operatorId,
            $this->clientKey,
            $this->issuedAt,
        );
    }
}
