<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Application;

final readonly class JobRecord
{
    public function __construct(
        public int $id,
        public string $jobKey,
        public int $tenantId,
        public string $taskType,
        public string $status,
        public int $attemptCount,
        public int $maxAttempts,
        public int $revision,
        public ?string $lastErrorCode,
        public string $availableAt,
        public string $createdAt,
        public string $updatedAt,
        public ?string $completedAt,
    ) {}

    /** @return array<string, int|string|null> */
    public function toPublicArray(): array
    {
        return [
            'job_key' => $this->jobKey,
            'task_type' => $this->taskType,
            'status' => $this->status,
            'attempt_count' => $this->attemptCount,
            'max_attempts' => $this->maxAttempts,
            'revision' => $this->revision,
            'last_error_code' => $this->lastErrorCode,
            'available_at' => $this->availableAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'completed_at' => $this->completedAt,
        ];
    }
}
