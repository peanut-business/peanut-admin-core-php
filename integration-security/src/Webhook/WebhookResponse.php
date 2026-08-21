<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Webhook;

final readonly class WebhookResponse
{
    public function __construct(public int $statusCode, public int $durationMs)
    {
        if ($statusCode < 100 || $statusCode > 599 || $durationMs < 0 || $durationMs > 30000) {
            throw \PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException::invalid();
        }
    }
}
