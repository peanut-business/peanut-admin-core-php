<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Media;

final readonly class ImageVariantPlan
{
    public function __construct(
        public string $variantKey,
        public int $width,
        public int $height,
        public string $fit,
        public string $mediaType,
        public string $storageSuffix,
    ) {}

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
