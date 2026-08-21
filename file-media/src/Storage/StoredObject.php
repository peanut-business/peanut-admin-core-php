<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

final readonly class StoredObject
{
    public function __construct(
        public string $providerKey,
        public string $storageKey,
    ) {}
}
