<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Task;

use PeanutAdmin\OpsConsole\Application\OpsConsoleException;
use PeanutAdmin\TaskJob\Application\JobRecord;
use Throwable;

final class TaskJobStatusProjection
{
    public static function fromRecord(JobRecord $record): OpsTask
    {
        if (!OpsTask::supportsTaskType($record->taskType)) {
            throw OpsConsoleException::taskNotFound();
        }
        try {
            return new OpsTask(
                $record->jobKey,
                $record->taskType,
                $record->status,
                $record->attemptCount,
                $record->maxAttempts,
                $record->revision,
                $record->lastErrorCode,
                $record->availableAt,
                $record->createdAt,
                $record->updatedAt,
                $record->completedAt,
            );
        } catch (Throwable) {
            throw OpsConsoleException::taskUnavailable();
        }
    }

    private function __construct() {}
}
