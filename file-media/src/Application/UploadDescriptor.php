<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Application;

final readonly class UploadDescriptor
{
    public function __construct(
        public string $sourcePath,
        public string $originalName,
        public string $mediaType,
        public int $sizeBytes,
        public string $sha256,
    ) {}
}
