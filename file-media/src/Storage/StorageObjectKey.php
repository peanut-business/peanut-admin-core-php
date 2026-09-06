<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage;

/** Validates storage-provider object keys without assigning business ownership. */
final class StorageObjectKey
{
    /** Normalizes separators and rejects unsafe or malformed object keys. */
    public static function assert(string $objectKey): string
    {
        $objectKey = trim(str_replace('\\', '/', $objectKey), '/');
        if ($objectKey === ''
            || str_contains($objectKey, '..')
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9/._-]{1,254}$#D', $objectKey) !== 1) {
            throw new \InvalidArgumentException('存储对象路径无效');
        }

        return $objectKey;
    }
}
