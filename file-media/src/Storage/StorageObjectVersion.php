<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

final readonly class StorageObjectVersion
{
    public function __construct(
        public string $versionId,
        public bool $isLatest,
        public bool $deleteMarker,
        public ?int $sizeBytes = null,
        public ?string $etag = null,
    ) {
        self::assertId($versionId);
        if (($deleteMarker && ($sizeBytes !== null || $etag !== null))
            || (!$deleteMarker && ($sizeBytes === null || $sizeBytes < 0))
        ) {
            throw new \UnexpectedValueException('STORAGE_OBJECT_VERSION_INVALID');
        }
    }

    public static function assertId(string $versionId): string
    {
        if ($versionId === '' || strlen($versionId) > 1024 || preg_match('/^[\x21-\x7e]+$/D', $versionId) !== 1) {
            throw new \InvalidArgumentException('STORAGE_VERSION_ID_INVALID');
        }
        return $versionId;
    }
}
