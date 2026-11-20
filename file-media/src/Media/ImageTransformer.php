<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Media;

use Intervention\Image\ImageManager;

/** The host selects the installed image driver explicitly. */
final readonly class ImageTransformer
{
    public function __construct(private ImageManager $manager, private ImageMetadataInspector $inspector = new ImageMetadataInspector()) {}

    public function resize(string $source, string $destination, int $width, int $height): void
    {
        if ($width < 1 || $width > 4096 || $height < 1 || $height > 4096) {
            throw new \InvalidArgumentException('Image dimensions must be positive.');
        }
        if (!in_array(strtolower(pathinfo($destination, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true)) {
            throw new \InvalidArgumentException('Unsupported output image extension.');
        }
        $this->inspector->inspect($source);
        $this->manager->read($source)->scaleDown($width, $height)->save($destination);
    }
}
