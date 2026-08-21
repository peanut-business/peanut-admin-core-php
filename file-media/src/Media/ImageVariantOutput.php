<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Media;

use PeanutAdmin\FileMedia\Application\FileMediaException;

final readonly class ImageVariantOutput
{
    public function __construct(
        public ImageVariantPlan $plan,
        public int $sizeBytes,
        public string $sha256,
    ) {
        if ($sizeBytes < 1 || $sizeBytes > ImageMetadataInspector::HARD_MAX_BYTES
            || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1
        ) {
            throw FileMediaException::imageInvalid();
        }
    }

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
