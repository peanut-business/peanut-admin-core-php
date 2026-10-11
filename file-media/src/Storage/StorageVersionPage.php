<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

final readonly class StorageVersionPage
{
    /** @param list<StorageObjectVersion> $versions */
    public function __construct(
        public array $versions,
        public ?StorageVersionCursor $nextCursor = null,
        public ?string $requestId = null,
    ) {
        if (!array_is_list($versions) || count($versions) > 1000) {
            throw new \UnexpectedValueException('STORAGE_VERSION_PAGE_INVALID');
        }
        foreach ($versions as $version) {
            if (!$version instanceof StorageObjectVersion) {
                throw new \UnexpectedValueException('STORAGE_VERSION_PAGE_INVALID');
            }
        }
    }
}
