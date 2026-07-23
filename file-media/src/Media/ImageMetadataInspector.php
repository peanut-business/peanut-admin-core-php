<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Media;

use PeanutAdmin\FileMedia\Application\FileMediaException;

final readonly class ImageMetadataInspector
{
    public function inspect(string $sourcePath): ImageMetadata
    {
        if ($sourcePath === '' || is_link($sourcePath) || !is_file($sourcePath) || !is_readable($sourcePath)) {
            throw FileMediaException::imageInvalid();
        }
        $size = @getimagesize($sourcePath);
        if (!is_array($size)) {
            throw FileMediaException::imageInvalid();
        }

        return new ImageMetadata($size[0], $size[1], $size['mime']);
    }
}
