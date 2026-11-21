<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Media;

use PeanutAdmin\FileMedia\Application\FileMediaException;
use Throwable;

final readonly class ImageMetadataInspector
{
    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;
    public const HARD_MAX_BYTES = 50 * 1024 * 1024;

    public function __construct(private int $maxBytes = self::DEFAULT_MAX_BYTES)
    {
        if ($maxBytes < 1 || $maxBytes > self::HARD_MAX_BYTES) {
            throw FileMediaException::imageInvalid();
        }
    }

    public function inspect(string $sourcePath): ImageMetadata
    {
        return $this->scanImage($sourcePath, false)[0];
    }

    public function inspectWithEvidence(string $sourcePath): ImageInspection
    {
        [$metadata, $byteLength, $sha256] = $this->scanImage($sourcePath, true);

        return new ImageInspection($metadata, $byteLength, $sha256);
    }

    /** @return array{ImageMetadata, int, string} */
    private function scanImage(string $sourcePath, bool $computeHash): array
    {
        if ($sourcePath === '' || is_link($sourcePath) || !is_file($sourcePath) || !is_readable($sourcePath)) {
            throw FileMediaException::imageInvalid();
        }
        $stream = @fopen($sourcePath, 'rb');
        if ($stream === false) {
            throw FileMediaException::imageInvalid();
        }
        try {
            $initial = fstat($stream);
            if ($initial === false || $initial['size'] < 1 || $initial['size'] > $this->maxBytes) {
                throw FileMediaException::imageInvalid();
            }
            $byteLength = (int) $initial['size'];
            $sha256 = '';
            if ($computeHash) {
                $hasher = hash_init('sha256');
                $readBytes = 0;
                while (!feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if (!is_string($chunk)) {
                        throw FileMediaException::imageInvalid();
                    }
                    $readBytes += strlen($chunk);
                    if ($readBytes > $this->maxBytes) {
                        throw FileMediaException::imageInvalid();
                    }
                    hash_update($hasher, $chunk);
                }
                $final = fstat($stream);
                if ($final === false || $final['size'] !== $initial['size'] || $readBytes !== $initial['size']) {
                    throw FileMediaException::imageInvalid();
                }
                $sha256 = hash_final($hasher);
            }
            $size = @getimagesize($sourcePath);
            if ($size === false) {
                throw FileMediaException::imageInvalid();
            }

            return [new ImageMetadata((int) $size[0], (int) $size[1], $size['mime']), $byteLength, $sha256];
        } catch (Throwable $exception) {
            if ($exception instanceof FileMediaException) {
                throw $exception;
            }
            throw FileMediaException::imageInvalid();
        } finally {
            fclose($stream);
        }
    }
}
