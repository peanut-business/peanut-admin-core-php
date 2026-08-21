<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context;

/** Tenant-owned business-member identity established by a Host authentication boundary. */
class AuthenticatedMemberContext
{
    public readonly int $tenantId;

    public readonly int $memberId;

    public readonly string $credentialFingerprint;

    public readonly string $requestId;

    public function __construct(
        int $tenantId,
        int $memberId,
        string $credentialFingerprint,
        string $requestId,
    ) {
        $credentialFingerprint = trim($credentialFingerprint);
        $requestId = trim($requestId);
        if ($tenantId < 1 || $memberId < 1 || $credentialFingerprint === '' || $requestId === '') {
            throw new \DomainException('MEMBER_TENANT_CONTEXT_UNAVAILABLE');
        }

        $this->tenantId = $tenantId;
        $this->memberId = $memberId;
        $this->credentialFingerprint = $credentialFingerprint;
        $this->requestId = $requestId;
    }
}
