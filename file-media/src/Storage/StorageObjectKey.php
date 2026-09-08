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
        $segments = explode('/', $objectKey);
        if ($objectKey === ''
            || str_contains($objectKey, '..')
            || in_array('', $segments, true)
            || in_array('.', $segments, true)
            || preg_match('#^[A-Za-z0-9][A-Za-z0-9/._-]{0,254}$#D', $objectKey) !== 1) {
            throw new \InvalidArgumentException('存储对象路径无效');
        }

        return $objectKey;
    }
}
