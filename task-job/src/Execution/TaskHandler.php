<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Execution;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface TaskHandler
{
    public function key(): string;

    /**
     * Implementations must use $execution->jobKey as their stable side-effect
     * idempotency key before reporting success.
     */
    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void;
}
