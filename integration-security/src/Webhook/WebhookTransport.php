<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Webhook;

interface WebhookTransport
{
    public function send(WebhookRequest $request): WebhookResponse;
}
