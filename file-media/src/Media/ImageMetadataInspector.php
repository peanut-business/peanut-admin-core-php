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
        return $this->inspectWithEvidence($sourcePath)->metadata;
    }

    public function inspectWithEvidence(string $sourcePath): ImageInspection
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
            $bytes = '';
            $hash = hash_init('sha256');
            while (!feof($stream)) {
                $chunk = fread($stream, 8192);
                if (!is_string($chunk)) {
                    throw FileMediaException::imageInvalid();
                }
                $bytes .= $chunk;
                if (strlen($bytes) > $this->maxBytes) {
                    throw FileMediaException::imageInvalid();
                }
                hash_update($hash, $chunk);
            }
            $final = fstat($stream);
            if ($final === false || $final['size'] !== $initial['size'] || strlen($bytes) !== $initial['size']) {
                throw FileMediaException::imageInvalid();
            }
            $size = @getimagesizefromstring($bytes);
            if (!is_array($size)) {
                throw FileMediaException::imageInvalid();
            }

            return new ImageInspection(
                new ImageMetadata($size[0], $size[1], $size['mime']),
                strlen($bytes),
                hash_final($hash),
            );
        } catch (FileMediaException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw FileMediaException::imageInvalid();
        } finally {
            fclose($stream);
        }
    }
}
