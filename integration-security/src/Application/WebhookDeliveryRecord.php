<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Application;

final readonly class WebhookDeliveryRecord implements \JsonSerializable
{
    public function __construct(
        public string $deliveryKey,
        public string $endpointKey,
        public string $eventType,
        public string $status,
        public int $attemptCount,
        public ?int $lastStatusCode,
        public ?string $lastErrorCode,
        public string $createdAt,
        public string $updatedAt,
        public ?string $deliveredAt,
    ) {}

    public function jsonSerialize(): array
    {
        return [
            'delivery_key' => $this->deliveryKey,
            'endpoint_key' => $this->endpointKey,
            'event_type' => $this->eventType,
            'status' => $this->status,
            'attempt_count' => $this->attemptCount,
            'last_status_code' => $this->lastStatusCode,
            'last_error_code' => $this->lastErrorCode,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'delivered_at' => $this->deliveredAt,
        ];
    }
}
