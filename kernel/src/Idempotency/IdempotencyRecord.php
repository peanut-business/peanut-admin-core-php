<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Idempotency;

final readonly class IdempotencyRecord
{
    /** @param array<string, mixed>|null $responseBody */
    public function __construct(
        public int $id,
        public string $status,
        public string $requestHash,
        public ?int $responseStatus,
        public ?array $responseBody,
        public ?string $resourceType,
        public ?string $resourceId,
        public bool $created,
    ) {}

    public function acquiredForExecution(): bool
    {
        return $this->created;
    }

    public function replayable(): bool
    {
        return in_array($this->status, ['completed', 'failed'], true)
            && $this->responseStatus !== null
            && $this->responseBody !== null;
    }
}
