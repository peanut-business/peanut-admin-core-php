<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

interface StorageProvider
{
    public function key(): string;

    public function store(int $tenantId, string $fileKey, string $sourcePath): StoredObject;

    /** @return resource */
    public function open(string $storageKey);

    public function remove(string $storageKey): void;
}
