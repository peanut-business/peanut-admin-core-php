<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Application;

use RuntimeException;

final class OpsConsoleException extends RuntimeException
{
    private function __construct(public readonly string $problemCode, public readonly int $status)
    {
        parent::__construct($problemCode);
    }

    public static function invalid(): self
    {
        return new self('OPS_REQUEST_INVALID', 400);
    }

    public static function denied(): self
    {
        return new self('OPS_PERMISSION_DENIED', 403);
    }

    public static function providerNotFound(): self
    {
        return new self('OPS_PROVIDER_NOT_FOUND', 404);
    }

    public static function taskNotFound(): self
    {
        return new self('OPS_TASK_NOT_FOUND', 404);
    }

    public static function idempotencyConflict(): self
    {
        return new self('OPS_IDEMPOTENCY_CONFLICT', 409);
    }

    public static function operationInProgress(): self
    {
        return new self('OPS_OPERATION_IN_PROGRESS', 409);
    }

    public static function revisionConflict(): self
    {
        return new self('OPS_REVISION_CONFLICT', 409);
    }

    public static function restoreTargetInvalid(): self
    {
        return new self('OPS_RESTORE_TARGET_INVALID', 422);
    }

    public static function maintenanceInvalid(): self
    {
        return new self('OPS_MAINTENANCE_INVALID', 422);
    }

    public static function statusUnavailable(): self
    {
        return new self('OPS_STATUS_UNAVAILABLE', 503);
    }

    public static function providerUnavailable(): self
    {
        return new self('OPS_PROVIDER_UNAVAILABLE', 503);
    }

    public static function taskUnavailable(): self
    {
        return new self('OPS_TASK_UNAVAILABLE', 503);
    }

    public static function logsUnavailable(): self
    {
        return new self('OPS_LOGS_UNAVAILABLE', 503);
    }

    public static function internal(): self
    {
        return new self('OPS_INTERNAL_ERROR', 500);
    }
}
