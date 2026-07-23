<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

use PeanutAdmin\FileMedia\Application\FileMediaException;
use Throwable;

/** Adapts the existing local/private provider to the object-storage contract. */
final readonly class PrivateStorageAdapter implements ObjectStorageProvider
{
    public function __construct(private StorageProvider $storage) {}

    public function key(): string
    {
        return $this->storage->key();
    }

    public function capabilities(): ObjectStorageCapabilities
    {
        return new ObjectStorageCapabilities();
    }

    public function store(int $tenantId, string $fileKey, string $sourcePath): StoredObject
    {
        return $this->storage->store($tenantId, $fileKey, $sourcePath);
    }

    public function open(string $storageKey)
    {
        return $this->storage->open($storageKey);
    }

    public function remove(string $storageKey): void
    {
        $this->storage->remove($storageKey);
    }

    public function head(string $storageKey): StoredObjectMetadata
    {
        $stream = null;
        try {
            $stream = $this->storage->open($storageKey);
            $hash = hash_init('sha256');
            $size = 0;
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if (!is_string($chunk)) {
                    throw FileMediaException::storageUnavailable();
                }
                $size += strlen($chunk);
                hash_update($hash, $chunk);
            }
            if ($size < 1) {
                throw FileMediaException::storageUnavailable();
            }

            return new StoredObjectMetadata($this->key(), $storageKey, $size, hash_final($hash));
        } catch (FileMediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw FileMediaException::storageUnavailable();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
