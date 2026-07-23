<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Media;

use PeanutAdmin\FileMedia\Application\FileMediaException;

final readonly class ImageInspection
{
    public function __construct(
        public ImageMetadata $metadata,
        public int $sizeBytes,
        public string $sha256,
    ) {
        if ($sizeBytes < 1 || $sizeBytes > ImageMetadataInspector::HARD_MAX_BYTES
            || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1
        ) {
            throw FileMediaException::imageInvalid();
        }
    }
}
