<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Identity\AccountStatus;
use PeanutAdmin\Kernel\Membership\TenantMemberStatus;
use PeanutAdmin\Kernel\Tenancy\TenantStatus;

final readonly class SessionAuthenticationRecord
{
    public function __construct(
        public int $tokenId,
        public string $tokenType,
        public string $tokenStatus,
        public DateTimeImmutable $tokenExpiresAt,
        public int $sessionId,
        public string $sessionKey,
        public string $sessionStatus,
        public int $tenantId,
        public int $accountId,
        public int $memberId,
        public string $clientKey,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $idleExpiresAt,
        public DateTimeImmutable $absoluteExpiresAt,
        public int $accountSecurityRevision,
        public int $tenantSecurityRevision,
        public int $memberSecurityRevision,
        public AccountStatus $accountStatus,
        public int $currentAccountSecurityRevision,
        public TenantStatus $tenantStatus,
        public int $currentTenantSecurityRevision,
        public TenantMemberStatus $memberStatus,
        public int $currentMemberSecurityRevision,
        public int $authorizationRevision,
    ) {}

    public function validated(): ValidatedTenantSession
    {
        return new ValidatedTenantSession(
            $this->sessionId,
            $this->sessionKey,
            $this->tenantId,
            $this->accountId,
            $this->memberId,
            $this->clientKey,
            $this->issuedAt,
            $this->authorizationRevision,
        );
    }
}
