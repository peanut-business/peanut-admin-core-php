<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Adapter;

use PDO;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface WorkflowSideEffectPublisher
{
    public function connection(): PDO;

    public function publish(
        PDO $pdo,
        AuthorizedOperationContext $context,
        WorkflowTransitionEffects $effects,
        string $parentIdempotencyKey,
    ): void;
}
