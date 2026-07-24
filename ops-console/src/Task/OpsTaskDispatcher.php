<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Task;

use PeanutAdmin\Kernel\Context\PlatformContext;

interface OpsTaskDispatcher
{
    /** Submission and audit must commit atomically. */
    public function dispatch(PlatformContext $context, OpsTaskSubmission $submission): OpsTask;

    public function find(PlatformContext $context, string $taskKey): OpsTask;
}
