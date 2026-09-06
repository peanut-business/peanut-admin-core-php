<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage\Driver;

use PeanutAdmin\FileMedia\Storage\StorageDriver;
use PeanutAdmin\FileMedia\Storage\StorageObjectKey;

/** Host-filesystem storage driver rooted at an absolute Host-selected directory. */
final readonly class LocalStorageDriver implements StorageDriver
{
    /** Validates that the Host-selected root is absolute on the running platform. */
    public function __construct(
        private string $root,
        private bool $private,
    ) {
        $absolute = DIRECTORY_SEPARATOR === '\\'
            ? preg_match('#^(?:[A-Za-z]:[\\\\/]|\\\\\\\\)#D', $root) === 1
            : str_starts_with($root, '/');
        if (!$absolute) {
            throw new \RuntimeException('本地存储根目录无效');
        }
    }

    /** @inheritDoc */
    public function put(string $objectKey, string $sourcePath): void
    {
        $target = $this->path($objectKey);
        $directory = dirname($target);
        $mode = $this->private ? 0700 : 0755;
        if ((!is_dir($directory) && !mkdir($directory, $mode, true)) || !copy($sourcePath, $target)) {
            throw new \RuntimeException('本地文件写入失败');
        }

        @chmod($target, $this->private ? 0600 : 0644);
    }

    /** @inheritDoc */
    public function delete(string $objectKey): void
    {
        $path = $this->path($objectKey);
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException('本地文件删除失败');
        }
    }

    /** @inheritDoc */
    public function downloadTo(string $objectKey, string $targetPath): void
    {
        if (!copy($this->path($objectKey), $targetPath)) {
            throw new \RuntimeException('本地文件读取失败');
        }
    }

    /** @inheritDoc */
    public function localPath(string $objectKey): ?string
    {
        return $this->path($objectKey);
    }

    /** Resolves a validated object key beneath the fixed storage root. */
    private function path(string $objectKey): string
    {
        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, StorageObjectKey::assert($objectKey));
    }
}
