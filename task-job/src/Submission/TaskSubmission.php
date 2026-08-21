<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Submission;

final readonly class TaskSubmission
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $handlerKey,
        public array $payload,
        public int $maxAttempts = 3,
        public int $initialDelaySeconds = 0,
    ) {}
}
