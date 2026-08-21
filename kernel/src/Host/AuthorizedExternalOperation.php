<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;

final readonly class AuthorizedExternalOperation
{
    /** @param list<RequestedTargetSet> $targets */
    private function __construct(
        public TenantContext|PlatformContext $context,
        public ExternalOperationDefinition $operation,
        public ?object $queryConstraint = null,
        public array $targets = [],
    ) {}

}
