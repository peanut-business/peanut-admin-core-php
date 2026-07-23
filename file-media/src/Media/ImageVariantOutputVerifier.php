<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Media;

use PeanutAdmin\FileMedia\Application\FileMediaException;

final readonly class ImageVariantOutputVerifier
{
    public function __construct(private ImageMetadataInspector $inspector = new ImageMetadataInspector()) {}

    public function verify(string $destinationPath, ImageVariantPlan $plan): ImageVariantOutput
    {
        if ($destinationPath === '' || is_link($destinationPath) || !is_file($destinationPath) || !is_readable($destinationPath)) {
            throw FileMediaException::imageInvalid();
        }
        $metadata = $this->inspector->inspect($destinationPath);
        $size = filesize($destinationPath);
        $sha256 = hash_file('sha256', $destinationPath);
        if ($metadata->width !== $plan->width || $metadata->height !== $plan->height
            || $metadata->mediaType !== $plan->mediaType || !is_int($size) || $size < 1
            || !is_string($sha256) || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1
        ) {
            throw FileMediaException::imageInvalid();
        }

        return new ImageVariantOutput($plan, $size, $sha256);
    }
}
