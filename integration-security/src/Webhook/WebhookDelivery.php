<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Webhook;

final readonly class WebhookDelivery
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public string $endpointKey,
        public string $deliveryKey,
        public string $eventType,
        public string $payloadJson,
        public string $payloadSha256,
        public string $url,
        public string $secretCiphertext,
        public string $secretKeyId,
        public int $attemptNumber,
        public string $leaseDigest,
    ) {}
}
