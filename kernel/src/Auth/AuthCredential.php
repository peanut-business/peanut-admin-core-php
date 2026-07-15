<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Identity\AccountStatus;
use PeanutAdmin\Kernel\Identity\CredentialStatus;

final readonly class AuthCredential
{
    public function __construct(
        public int $credentialId,
        public int $accountId,
        public string $secretHash,
        public CredentialStatus $credentialStatus,
        public int $failedAttempts,
        public ?DateTimeImmutable $lockedUntil,
        public ?DateTimeImmutable $expiresAt,
        public AccountStatus $accountStatus,
    ) {}
}
