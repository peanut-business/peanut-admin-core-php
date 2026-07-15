<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;

final readonly class PlatformContext
{
    private function __construct(
        public int $accountId,
        public int $operatorId,
        public string $sessionKey,
        public string $clientKey,
        public string $requestId,
        public DateTimeImmutable $issuedAt,
    ) {}

    public static function fromValidatedSession(
        ValidatedPlatformSession $session,
        string $requestId,
    ): self {
        return new self(
            $session->accountId,
            $session->operatorId,
            $session->sessionKey,
            $session->clientKey,
            $requestId,
            $session->issuedAt,
        );
    }
}
