<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Adapter;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface WorkflowSideEffectPublisher
{
    /** Fail unless this publisher participates in the command's active transaction. */
    public function assertTransactionParticipation(): void;

    public function publish(
        AuthorizedOperationContext $context,
        WorkflowTransitionEffects $effects,
        string $parentIdempotencyKey,
    ): void;
}
