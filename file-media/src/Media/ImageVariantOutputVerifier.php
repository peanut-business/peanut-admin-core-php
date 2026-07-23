<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Media;

use PeanutAdmin\FileMedia\Application\FileMediaException;

final readonly class ImageVariantOutputVerifier
{
    private ImageMetadataInspector $inspector;

    public function __construct(int $maxBytes = ImageMetadataInspector::DEFAULT_MAX_BYTES)
    {
        $this->inspector = new ImageMetadataInspector($maxBytes);
    }

    public function verify(string $destinationPath, ImageVariantPlan $plan): ImageVariantOutput
    {
        $inspection = $this->inspector->inspectWithEvidence($destinationPath);
        if ($inspection->metadata->width !== $plan->width || $inspection->metadata->height !== $plan->height
            || $inspection->metadata->mediaType !== $plan->mediaType
        ) {
            throw FileMediaException::imageInvalid();
        }

        return new ImageVariantOutput($plan, $inspection->sizeBytes, $inspection->sha256);
    }
}
