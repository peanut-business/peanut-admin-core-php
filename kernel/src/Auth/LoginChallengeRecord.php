<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;

final readonly class LoginChallengeRecord
{
    public function __construct(
        public int $id,
        public int $accountId,
        public string $purpose,
        public string $status,
        public ?string $sourceSessionKey,
        public DateTimeImmutable $expiresAt,
    ) {}
}
