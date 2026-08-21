<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Adapter;

use PDO;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface WorkflowAuthorizationResolver
{
    public function connection(): PDO;

    /** @param non-empty-list<string> $permissionKeys */
    public function authorize(
        AuthorizedOperationContext $trustedBasis,
        string $resourceKey,
        string $operation,
        array $permissionKeys,
        string $subjectKey,
    ): AuthorizedOperationContext;
}
