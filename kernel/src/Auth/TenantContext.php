<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;

final readonly class TenantContext
{
    private function __construct(
        public int $tenantId,
        public int $accountId,
        public int $memberId,
        public string $sessionKey,
        public string $clientKey,
        public string $requestId,
        public DateTimeImmutable $issuedAt,
        public int $authorizationRevision,
    ) {}

    public static function fromValidatedSession(
        ValidatedTenantSession $session,
        string $requestId,
    ): self {
        return new self(
            $session->tenantId,
            $session->accountId,
            $session->memberId,
            $session->sessionKey,
            $session->clientKey,
            $requestId,
            $session->issuedAt,
            $session->authorizationRevision,
        );
    }
}
