<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Identity\AccountStatus;
use PeanutAdmin\Kernel\Identity\CredentialStatus;
use PeanutAdmin\Kernel\Platform\PlatformOperatorStatus;

final readonly class PlatformAuthPrincipal
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
        public int $accountSecurityRevision,
        public int $operatorId,
        public PlatformOperatorStatus $operatorStatus,
        public int $operatorSecurityRevision,
    ) {}
}
