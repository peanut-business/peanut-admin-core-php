<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Adapter;

use PDO;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface WorkflowSubjectRevisionResolver
{
    public function connection(): PDO;

    /** @return array{revision_key: string, sha256: string} */
    public function resolve(
        AuthorizedOperationContext $context,
        string $subjectType,
        string $subjectKey,
        string $expectedRevisionKey,
    ): array;
}
