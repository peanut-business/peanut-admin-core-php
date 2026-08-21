<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context;

use PeanutAdmin\Kernel\Auth\TenantContext;

final readonly class AuthorizationDecision
{
    /** @param list<RequestedTargetSet> $targets */
    private function __construct(
        public TenantContext $tenantContext,
        public string $resourceKey,
        public string $operation,
        public array $targets,
        public string $basisDigest,
    ) {}

    /** @param list<RequestedTargetSet> $targets */
    public static function allow(
        TenantContext $tenantContext,
        string $resourceKey,
        string $operation,
        array $targets,
        string $basisDigest,
    ): self {
        return new self($tenantContext, $resourceKey, $operation, $targets, $basisDigest);
    }
}
