<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Adapter;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface WorkflowAuthorizationResolver
{
    /** @param non-empty-list<string> $permissionKeys */
    public function authorize(
        AuthorizedOperationContext $trustedBasis,
        string $resourceKey,
        string $operation,
        array $permissionKeys,
        string $subjectKey,
    ): AuthorizedOperationContext;
}
