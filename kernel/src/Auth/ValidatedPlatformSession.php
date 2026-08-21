<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;

final readonly class ValidatedPlatformSession
{
    public function __construct(
        public int $sessionId,
        public string $sessionKey,
        public int $accountId,
        public int $operatorId,
        public string $clientKey,
        public DateTimeImmutable $issuedAt,
    ) {}
}
