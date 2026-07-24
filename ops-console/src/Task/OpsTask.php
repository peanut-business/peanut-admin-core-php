<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Task;

use InvalidArgumentException;
use PeanutAdmin\OpsConsole\Support\Contract;

final readonly class OpsTask
{
    public function __construct(
        public string $taskKey,
        public string $taskType,
        public string $status,
        public int $attemptCount,
        public int $maximumAttempts,
        public int $revision,
        public ?string $lastErrorCode,
        public string $availableAt,
        public string $createdAt,
        public string $updatedAt,
        public ?string $completedAt,
    ) {
        Contract::opaqueKey($taskKey, 'job_');
        Contract::qualifiedKey($taskType);
        if (!in_array($status, ['queued', 'running', 'succeeded', 'dead', 'cancelled'], true)
            || $attemptCount < 0 || $maximumAttempts < 1 || $maximumAttempts > 10
            || $attemptCount > $maximumAttempts || $revision < 1
            || (in_array($status, ['succeeded', 'dead', 'cancelled'], true) !== ($completedAt !== null))
        ) {
            throw new InvalidArgumentException('Invalid operations task status.');
        }
        if ($lastErrorCode !== null) Contract::stableCode($lastErrorCode);
        Contract::instant($availableAt);
        Contract::instant($createdAt);
        Contract::instant($updatedAt);
        if ($completedAt !== null) Contract::instant($completedAt);
    }

    /** @return array<string, int|string|null> */
    public function toPublicArray(): array
    {
        return [
            'task_key' => $this->taskKey, 'task_type' => $this->taskType,
            'status' => $this->status, 'attempt_count' => $this->attemptCount,
            'max_attempts' => $this->maximumAttempts, 'revision' => $this->revision,
            'last_error_code' => $this->lastErrorCode, 'available_at' => $this->availableAt,
            'created_at' => $this->createdAt, 'updated_at' => $this->updatedAt,
            'completed_at' => $this->completedAt,
        ];
    }
}
