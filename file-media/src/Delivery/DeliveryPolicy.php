<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Delivery;

use PeanutAdmin\FileMedia\Application\FileMediaException;

final readonly class DeliveryPolicy
{
    public function __construct(
        public int $privateMaxTtlSeconds = 300,
        public int $publicMaxTtlSeconds = 3600,
    ) {
        if ($privateMaxTtlSeconds < 1 || $privateMaxTtlSeconds > 900
            || $publicMaxTtlSeconds < 1 || $publicMaxTtlSeconds > 86400
        ) {
            throw FileMediaException::deliveryInvalid();
        }
    }

    public function assertAllowed(DeliveryRequest $request): void
    {
        $limit = $request->visibility === DeliveryVisibility::Private
            ? $this->privateMaxTtlSeconds
            : $this->publicMaxTtlSeconds;
        if ($request->ttlSeconds > $limit) {
            throw FileMediaException::deliveryInvalid();
        }
        if ($request->visibility === DeliveryVisibility::Private
            && $request->replayMode !== ReplayMode::SingleUse
        ) {
            throw FileMediaException::deliveryDenied();
        }
    }
}
