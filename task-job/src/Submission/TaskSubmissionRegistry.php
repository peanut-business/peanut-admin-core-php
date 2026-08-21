<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Submission;

use PeanutAdmin\TaskJob\Application\TaskJobException;

final class TaskSubmissionRegistry
{
    /** @var array<string, TaskSubmissionProvider> */
    private array $providers = [];

    /** @param iterable<TaskSubmissionProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(TaskSubmissionProvider $provider): void
    {
        $key = $provider->taskType();
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $key) !== 1
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $provider->resourceKey()) !== 1
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $provider->operation()) !== 1
            || isset($this->providers[$key])
        ) {
            throw TaskJobException::invalid();
        }
        $this->providers[$key] = $provider;
    }

    public function require(string $taskType): TaskSubmissionProvider
    {
        return $this->providers[$taskType] ?? throw TaskJobException::invalid();
    }
}
