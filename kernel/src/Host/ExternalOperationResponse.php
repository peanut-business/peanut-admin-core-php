<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

final readonly class ExternalOperationResponse
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public array $body,
        public string $contentType = 'application/json',
        public array $headers = [],
    ) {}
}
