<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Media;

final readonly class ImageVariantOutput
{
    public function __construct(
        public ImageVariantPlan $plan,
        public int $sizeBytes,
        public string $sha256,
    ) {}

    /** @return array{variant_key: string, width: int, height: int, media_type: string, size_bytes: int, sha256: string} */
    public function persistenceMetadata(): array
    {
        return [
            'variant_key' => $this->plan->variantKey,
            'width' => $this->plan->width,
            'height' => $this->plan->height,
            'media_type' => $this->plan->mediaType,
            'size_bytes' => $this->sizeBytes,
            'sha256' => $this->sha256,
        ];
    }
}
