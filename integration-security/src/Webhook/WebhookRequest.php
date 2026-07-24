<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Webhook;

final readonly class WebhookRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public WebhookDestination $destination,
        public string $body,
        public array $headers,
        public int $timeoutSeconds = 10,
        public bool $followRedirects = false,
    ) {
        if ($followRedirects || $timeoutSeconds < 1 || $timeoutSeconds > 30) {
            throw \PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException::destinationDenied();
        }
    }
}
