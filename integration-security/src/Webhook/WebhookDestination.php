<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Webhook;

final readonly class WebhookDestination
{
    /** @param non-empty-list<string> $approvedAddresses */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public array $approvedAddresses,
    ) {}
}
