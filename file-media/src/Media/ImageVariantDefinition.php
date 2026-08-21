<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Media;

use PeanutAdmin\FileMedia\Application\FileMediaException;

final readonly class ImageVariantDefinition
{
    public function __construct(
        public string $key,
        public int $width,
        public int $height,
        public string $fit = 'cover',
        public string $mediaType = 'image/jpeg',
    ) {
        if (preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $key) !== 1
            || $width < 1 || $width > 4096 || $height < 1 || $height > 4096
            || !in_array($fit, ['contain', 'cover'], true)
            || !in_array($mediaType, ['image/jpeg', 'image/png'], true)
        ) {
            throw FileMediaException::imageInvalid();
        }
    }
}
