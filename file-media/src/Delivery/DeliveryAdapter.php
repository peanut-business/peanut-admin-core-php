<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Delivery;

interface DeliveryAdapter
{
    public function key(): string;

    public function supportsStorageProvider(string $providerKey): bool;

    public function issue(DeliveryRequest $request): DeliveryGrant;
}
