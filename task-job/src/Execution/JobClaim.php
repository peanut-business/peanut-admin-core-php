<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Execution;

final readonly class JobClaim
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public int $id,
        public string $jobKey,
        public int $tenantId,
        public string $handlerKey,
        public array $payload,
        public string $trustedEnvelope,
        public int $attemptNumber,
        public int $maxAttempts,
        public string $leaseToken,
    ) {}
}
