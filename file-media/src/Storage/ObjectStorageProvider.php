<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

interface ObjectStorageProvider extends StorageProvider
{
    public function capabilities(): ObjectStorageCapabilities;

    public function head(string $storageKey): StoredObjectMetadata;
}
