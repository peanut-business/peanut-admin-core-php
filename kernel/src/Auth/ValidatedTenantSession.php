<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;

final readonly class ValidatedTenantSession
{
    public function __construct(
        public int $sessionId,
        public string $sessionKey,
        public int $tenantId,
        public int $accountId,
        public int $memberId,
        public string $clientKey,
        public DateTimeImmutable $issuedAt,
        public int $authorizationRevision,
    ) {}
}
