<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Http;

final readonly class TenantAuthResponse
{
    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    public function __construct(
        public int $status,
        public ?array $body,
        public array $headers = [],
    ) {}
}
