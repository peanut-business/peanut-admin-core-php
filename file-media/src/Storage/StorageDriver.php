<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

/** Low-level object storage operations assembled by the consuming Host. */
interface StorageDriver
{
    /** Stores a source file under a validated provider object key. */
    public function put(string $objectKey, string $sourcePath): void;

    /** Requests object deletion and propagates provider failures. */
    public function delete(string $objectKey): void;

    /** Downloads the provider object into a Host-owned target path. */
    public function downloadTo(string $objectKey, string $targetPath): void;

    /** Returns a directly readable path only for local storage. */
    public function localPath(string $objectKey): ?string;
}
