<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;

final readonly class LoginChallengeRecord
{
    public function __construct(
        public int $id,
        public int $accountId,
        public string $clientKey,
        public string $purpose,
        public string $status,
        public ?string $sourceSessionKey,
        public ?string $ipAddress,
        public ?string $userAgentHash,
        public DateTimeImmutable $expiresAt,
    ) {}
}
