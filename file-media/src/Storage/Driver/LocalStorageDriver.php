<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Storage\Driver;

use PeanutAdmin\FileMedia\Storage\StorageDriver;
use PeanutAdmin\FileMedia\Storage\StorageObjectKey;

/** Host-filesystem storage driver confined to an absolute Host-selected directory. */
final readonly class LocalStorageDriver implements StorageDriver
{
    private string $root;

    /** Canonicalizes the Host-selected root without creating it. */
    public function __construct(
        string $root,
        private bool $private,
    ) {
        $absolute = DIRECTORY_SEPARATOR === '\\'
            ? preg_match('#^(?:[A-Za-z]:[\\\\/]|\\\\\\\\)#D', $root) === 1
            : str_starts_with($root, '/');
        if (!$absolute || str_contains($root, "\0")) {
            throw new \RuntimeException('本地存储根目录无效');
        }

        $this->root = $this->canonicalRoot($root);
    }

    /** @inheritDoc */
    public function put(string $objectKey, string $sourcePath): void
    {
        $objectKey = StorageObjectKey::assert($objectKey);
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException('待上传文件不可读');
        }
        $target = $this->objectPath($objectKey, true);
        $this->assertWritableTarget($target);
        $this->atomicCopy($sourcePath, $target, $this->private ? 0600 : 0644, '本地文件写入失败');
    }

    /** @inheritDoc */
    public function delete(string $objectKey): void
    {
        $path = $this->objectPath($objectKey, false);
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        $this->assertRegularFile($path);
        if (!unlink($path)) {
            throw new \RuntimeException('本地文件删除失败');
        }
    }

    /** @inheritDoc */
    public function downloadTo(string $objectKey, string $targetPath): void
    {
        $source = $this->objectPath($objectKey, false);
        $this->assertRegularFile($source);
        $this->assertWritableTarget($targetPath);
        $this->atomicCopy($source, $targetPath, 0600, '本地文件读取失败');
    }

    /** @inheritDoc */
    public function localPath(string $objectKey): ?string
    {
        $path = $this->objectPath($objectKey, false);
        if (file_exists($path) || is_link($path)) {
            $this->assertRegularFile($path);
        }

        return $path;
    }

    /** Resolves the root through its nearest existing ancestor. */
    private function canonicalRoot(string $root): string
    {
        $root = rtrim($root, '/\\');
        if ($root === '') {
            $root = DIRECTORY_SEPARATOR;
        } elseif (DIRECTORY_SEPARATOR === '\\' && preg_match('#^[A-Za-z]:$#D', $root) === 1) {
            $root .= DIRECTORY_SEPARATOR;
        }

        $missing = [];
        $probe = $root;
        while (!file_exists($probe) && !is_link($probe)) {
            $parent = dirname($probe);
            if ($parent === $probe) {
                throw new \RuntimeException('本地存储根目录无效');
            }
            $segment = basename($probe);
            if ($segment === '.' || $segment === '..') {
                throw new \RuntimeException('本地存储根目录无效');
            }
            array_unshift($missing, $segment);
            $probe = $parent;
        }

        $canonical = realpath($probe);
        if ($canonical === false || !is_dir($canonical)) {
            throw new \RuntimeException('本地存储根目录无效');
        }

        return $missing === []
            ? rtrim($canonical, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR
            : (rtrim($canonical, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $missing);
    }

    /** Resolves a validated key while rejecting symlinked or non-directory parents. */
    private function objectPath(string $objectKey, bool $createParents): string
    {
        $segments = explode('/', StorageObjectKey::assert($objectKey));
        $filename = array_pop($segments);
        $directory = $this->root;
        $this->ensureRoot($createParents);

        foreach ($segments as $segment) {
            $directory .= DIRECTORY_SEPARATOR . $segment;
            $this->ensureDirectory($directory, $createParents);
        }

        return $directory . DIRECTORY_SEPARATOR . $filename;
    }

    /** Creates the canonical root one segment at a time without following links. */
    private function ensureRoot(bool $create): void
    {
        if (is_link($this->root)) {
            throw new \RuntimeException('本地存储路径包含符号链接');
        }
        if (file_exists($this->root)) {
            $this->ensureDirectory($this->root, false);

            return;
        }
        if (!$create) {
            return;
        }

        $missing = [];
        $directory = $this->root;
        while (!file_exists($directory) && !is_link($directory)) {
            array_unshift($missing, basename($directory));
            $directory = dirname($directory);
        }
        $canonical = realpath($directory);
        if (is_link($directory) || $canonical === false || !$this->samePath($canonical, $directory)) {
            throw new \RuntimeException('本地存储路径包含符号链接');
        }

        foreach ($missing as $segment) {
            $directory .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($directory)
                || (!is_dir($directory) && !mkdir($directory, $this->private ? 0700 : 0755))) {
                throw new \RuntimeException('本地存储目录创建失败');
            }
        }

        $this->ensureDirectory($this->root, false);
    }

    /** Creates one safe directory when allowed and verifies its canonical confinement. */
    private function ensureDirectory(string $directory, bool $create): void
    {
        if (is_link($directory)) {
            throw new \RuntimeException('本地存储路径包含符号链接');
        }
        if (!file_exists($directory)) {
            if (!$create) {
                return;
            }
            if (!mkdir($directory, $this->private ? 0700 : 0755)) {
                throw new \RuntimeException('本地存储目录创建失败');
            }
        }
        if (!is_dir($directory)) {
            throw new \RuntimeException('本地存储路径不是目录');
        }

        $canonical = realpath($directory);
        if ($canonical === false || !$this->withinRoot($canonical)) {
            throw new \RuntimeException('本地存储路径越界');
        }
    }

    /** Rejects symlinks and special files before mutation. */
    private function assertWritableTarget(string $target): void
    {
        if (is_link($target) || (file_exists($target) && !is_file($target))) {
            throw new \RuntimeException('本地存储目标无效');
        }
    }

    /** Requires a directly addressable regular file rather than following a symlink. */
    private function assertRegularFile(string $path): void
    {
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('本地存储对象无效');
        }
    }

    /** Uses a Driver-owned same-directory temporary file and removes it on any failed replacement. */
    private function atomicCopy(string $source, string $target, int $mode, string $failure): void
    {
        $directory = dirname($target);
        if (!is_dir($directory) || is_link($directory)) {
            throw new \RuntimeException($failure);
        }
        $canonicalDirectory = realpath($directory);
        if ($canonicalDirectory === false) {
            throw new \RuntimeException($failure);
        }
        $target = rtrim($canonicalDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($target);

        $temporary = @tempnam($canonicalDirectory, '.peanut-storage-');
        if ($temporary === false || !$this->samePath(dirname($temporary), $canonicalDirectory)) {
            if (is_string($temporary) && is_file($temporary)) {
                @unlink($temporary);
            }
            throw new \RuntimeException($failure);
        }

        try {
            if (!@copy($source, $temporary) || !@chmod($temporary, $mode) || !@rename($temporary, $target)) {
                throw new \RuntimeException($failure);
            }
            $temporary = '';
        } finally {
            if ($temporary !== '' && is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /** Checks a canonical path against the canonical fixed root. */
    private function withinRoot(string $path): bool
    {
        return $this->samePath($path, $this->root)
            || str_starts_with($this->pathCase($path), $this->pathCase(rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR));
    }

    /** Compares platform paths using Windows' case-insensitive semantics. */
    private function samePath(string $left, string $right): bool
    {
        return $this->pathCase(rtrim($left, DIRECTORY_SEPARATOR))
            === $this->pathCase(rtrim($right, DIRECTORY_SEPARATOR));
    }

    private function pathCase(string $path): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
