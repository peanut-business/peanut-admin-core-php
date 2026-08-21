<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Media;

interface ImageVariantProcessor
{
    public function key(): string;

    /** The destination must be newly owned by the caller and outside public roots. */
    public function render(string $sourcePath, ImageVariantPlan $plan, string $destinationPath): void;
}
