<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Media;

use PeanutAdmin\FileMedia\Application\FileMediaException;

final readonly class ImageVariantPlan
{
    public function __construct(
        public string $variantKey,
        public int $width,
        public int $height,
        public string $fit,
        public string $mediaType,
        public string $storageSuffix,
    ) {
        $extension = $mediaType === 'image/png' ? 'png' : 'jpg';
        if (preg_match('/^[a-z][a-z0-9-]{0,31}$/D', $variantKey) !== 1
            || $width < 1 || $width > 4096 || $height < 1 || $height > 4096
            || !in_array($fit, ['contain', 'cover'], true)
            || !in_array($mediaType, ['image/jpeg', 'image/png'], true)
            || $storageSuffix !== 'variants/' . $variantKey . '.' . $extension
        ) {
            throw FileMediaException::imageInvalid();
        }
    }

    /** @return array{variant_key: string, width: int, height: int, fit: string, media_type: string} */
    public function publicMetadata(): array
    {
        return [
            'variant_key' => $this->variantKey,
            'width' => $this->width,
            'height' => $this->height,
            'fit' => $this->fit,
            'media_type' => $this->mediaType,
        ];
    }
}
