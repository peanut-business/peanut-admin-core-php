<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

/** An exact-key continuation; cannot broaden the next query to another object's history. */
final readonly class StorageVersionCursor
{
    public function __construct(public string $objectKey, public string $versionIdMarker)
    {
        if (StorageObjectKey::assert($objectKey) !== $objectKey) {
            throw new \InvalidArgumentException('STORAGE_VERSION_CURSOR_INVALID');
        }
        StorageObjectVersion::assertId($versionIdMarker);
    }
}
