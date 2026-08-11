<?php

declare(strict_types=1);

namespace PeanutAdmin\Workflow\Adapter;

use PeanutAdmin\Workflow\Application\WorkflowException;

final readonly class WorkflowTaskIntent
{
    public function __construct(public string $taskType)
    {
        if (strlen($taskType) < 1
            || strlen($taskType) > 64
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $taskType) !== 1) {
            throw WorkflowException::definitionInvalid();
        }
    }

    /** @return array{task_type: string} */
    public function toArray(): array
    {
        return ['task_type' => $this->taskType];
    }
}
