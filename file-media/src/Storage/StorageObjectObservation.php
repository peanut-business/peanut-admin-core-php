<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

/** Physical metadata only. ETag is opaque and never a SHA-256 content digest. */
final readonly class StorageObjectObservation
{
    public function __construct(
        public bool $exists,
        public ?int $sizeBytes = null,
        public ?string $mediaType = null,
        public bool $mediaTypeVerified = false,
        public ?string $etag = null,
        public ?string $versionId = null,
        public ?string $requestId = null,
    ) {
        if ((!$exists && ($sizeBytes !== null || $mediaType !== null || $etag !== null || $mediaTypeVerified))
            || ($exists && ($sizeBytes === null || $sizeBytes < 0))
            || ($mediaTypeVerified && $mediaType === null)
            || ($mediaType !== null && preg_match('~^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$~Di', $mediaType) !== 1)
        ) {
            throw new \UnexpectedValueException('STORAGE_OBJECT_METADATA_INVALID');
        }
        if ($versionId !== null) {
            StorageObjectVersion::assertId($versionId);
        }
    }
}
