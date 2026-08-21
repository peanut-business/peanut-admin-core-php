<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Media;

use PeanutAdmin\FileMedia\Application\FileMediaException;

final readonly class ImageMetadata
{
    public function __construct(
        public int $width,
        public int $height,
        public string $mediaType,
    ) {
        if ($width < 1 || $height < 1 || $width > 50000 || $height > 50000
            || $width * $height > 100_000_000
            || !in_array($mediaType, ['image/jpeg', 'image/png'], true)
        ) {
            throw FileMediaException::imageInvalid();
        }
    }

    /** @return array{width: int, height: int, media_type: string} */
    public function toArray(): array
    {
        return ['width' => $this->width, 'height' => $this->height, 'media_type' => $this->mediaType];
    }
}
