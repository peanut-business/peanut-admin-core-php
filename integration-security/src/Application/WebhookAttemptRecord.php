<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Application;

final readonly class WebhookAttemptRecord implements \JsonSerializable
{
    public function __construct(
        public int $attemptNumber,
        public string $outcome,
        public ?int $responseStatus,
        public ?string $errorCode,
        public int $durationMs,
        public string $attemptedAt,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'attempt_number' => $this->attemptNumber,
            'outcome' => $this->outcome,
            'response_status' => $this->responseStatus,
            'error_code' => $this->errorCode,
            'duration_ms' => $this->durationMs,
            'attempted_at' => $this->attemptedAt,
        ];
    }
}
