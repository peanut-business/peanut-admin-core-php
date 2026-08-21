<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Media;

use PeanutAdmin\FileMedia\Application\FileMediaException;

final readonly class ImageVariantPlanner
{
    /**
     * @param list<mixed> $definitions
     * @return list<ImageVariantPlan>
     */
    public function plan(ImageMetadata $source, array $definitions): array
    {
        if ($definitions === [] || count($definitions) > 16) {
            throw FileMediaException::imageInvalid();
        }
        $keys = [];
        $plans = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof ImageVariantDefinition || isset($keys[$definition->key])) {
                throw FileMediaException::imageInvalid();
            }
            $keys[$definition->key] = true;
            $extension = $definition->mediaType === 'image/png' ? 'png' : 'jpg';
            $plans[] = new ImageVariantPlan(
                $definition->key,
                min($definition->width, $source->width),
                min($definition->height, $source->height),
                $definition->fit,
                $definition->mediaType,
                'variants/' . $definition->key . '.' . $extension,
            );
        }

        return $plans;
    }
}
