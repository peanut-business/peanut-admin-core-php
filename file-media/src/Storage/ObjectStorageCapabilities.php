<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

final readonly class ObjectStorageCapabilities
{
    public function __construct(
        public bool $privateObjects = true,
        public bool $publicObjects = false,
        public bool $signedDelivery = false,
        public bool $cdnDelivery = false,
    ) {
        if (!$privateObjects) {
            throw new \InvalidArgumentException('Object storage must preserve private-object support.');
        }
        if (($publicObjects || $cdnDelivery) && !$signedDelivery) {
            throw new \InvalidArgumentException('Public and CDN delivery must use signed delivery.');
        }
    }
}
