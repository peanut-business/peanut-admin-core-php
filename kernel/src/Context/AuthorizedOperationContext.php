<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context;

use PeanutAdmin\Kernel\Auth\TenantContext;

final readonly class AuthorizedOperationContext
{
    /** @param list<RequestedTargetSet> $targets */
    private function __construct(
        public TenantContext $tenantContext,
        public string $resourceKey,
        public string $operation,
        public array $targets,
        public string $authorizationBasisDigest,
    ) {}

    public static function fromDecision(AuthorizationDecision $decision): self
    {
        return new self(
            $decision->tenantContext,
            $decision->resourceKey,
            $decision->operation,
            $decision->targets,
            $decision->basisDigest,
        );
    }
}
