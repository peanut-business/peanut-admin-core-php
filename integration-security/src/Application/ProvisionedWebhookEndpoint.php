<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Application;

final readonly class ProvisionedWebhookEndpoint
{
    public function __construct(public WebhookEndpoint $endpoint, public string $signingSecret) {}
}
