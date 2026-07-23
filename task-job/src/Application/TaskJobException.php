<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Application;

use RuntimeException;

final class TaskJobException extends RuntimeException
{
    private function __construct(public readonly string $problemCode, public readonly int $status)
    {
        parent::__construct($problemCode);
    }

    public static function denied(): self
    {
        return new self('TASK_PERMISSION_DENIED', 403);
    }

    public static function invalid(): self
    {
        return new self('TASK_REQUEST_INVALID', 422);
    }

    public static function notFound(): self
    {
        return new self('TASK_NOT_FOUND', 404);
    }

    public static function conflict(): self
    {
        return new self('TASK_IDEMPOTENCY_CONFLICT', 409);
    }

    public static function stateConflict(): self
    {
        return new self('TASK_STATE_CONFLICT', 409);
    }

    public static function handlerUnavailable(): self
    {
        return new self('TASK_HANDLER_UNAVAILABLE', 503);
    }

    public static function internal(): self
    {
        return new self('TASK_INTERNAL_ERROR', 500);
    }
}
