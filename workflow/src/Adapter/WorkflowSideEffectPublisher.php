<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Adapter;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface WorkflowSideEffectPublisher
{
    public function publish(
        AuthorizedOperationContext $context,
        WorkflowTransitionEffects $effects,
        string $parentIdempotencyKey,
    ): void;
}
