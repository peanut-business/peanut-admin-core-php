<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

/** Available low-level primitives, without a promise about a configured Bucket's visibility. */
final readonly class StorageDriverCapabilities
{
    public function __construct(
        public bool $objectInspection = false,
        public bool $objectAclRestriction = false,
        public bool $objectVersionListing = false,
        public bool $objectVersionRead = false,
        public bool $objectVersionDelete = false,
    ) {}
}
