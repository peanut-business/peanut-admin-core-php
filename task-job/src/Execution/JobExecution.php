<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Execution;

final readonly class JobExecution
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $jobKey,
        public int $tenantId,
        public int $attemptNumber,
        public array $payload,
    ) {}
}
