<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Delivery;

use PeanutAdmin\FileMedia\Application\FileMediaException;
use Throwable;

final readonly class DeliveryService
{
    public function __construct(
        private DeliveryAdapter $adapter,
        private DeliveryPolicy $policy,
    ) {}

    public function issue(DeliveryRequest $request): DeliveryGrant
    {
        $this->policy->assertAllowed($request);
        if (!$this->adapter->supportsStorageProvider($request->file->storageProviderKey)) {
            throw FileMediaException::deliveryUnavailable();
        }
        try {
            $grant = $this->adapter->issue($request);
        } catch (FileMediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw FileMediaException::deliveryUnavailable();
        }
        $expectedExpiry = $request->issuedAt->modify('+' . $request->ttlSeconds . ' seconds');
        if (!hash_equals($this->adapter->key(), $grant->adapterKey)
            || $grant->visibility !== $request->visibility
            || $grant->replayMode !== $request->replayMode
            || $grant->expiresAt->getTimestamp() !== $expectedExpiry->getTimestamp()
        ) {
            throw FileMediaException::deliveryUnavailable();
        }

        return $grant;
    }
}
