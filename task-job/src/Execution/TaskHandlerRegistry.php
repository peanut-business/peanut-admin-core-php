<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Execution;

use PeanutAdmin\TaskJob\Application\TaskJobException;

final class TaskHandlerRegistry
{
    /** @var array<string, TaskHandler> */
    private array $handlers = [];

    /** @param iterable<TaskHandler> $handlers */
    public function __construct(iterable $handlers = [])
    {
        foreach ($handlers as $handler) {
            $this->register($handler);
        }
    }

    public function register(TaskHandler $handler): void
    {
        $key = $handler->key();
        if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $key) !== 1 || isset($this->handlers[$key])) {
            throw TaskJobException::invalid();
        }
        $this->handlers[$key] = $handler;
    }

    public function require(string $key): TaskHandler
    {
        return $this->handlers[$key] ?? throw TaskJobException::handlerUnavailable();
    }
}
