<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Application;

use finfo;

final readonly class UploadPolicy
{
    public const DEFAULT_MAX_BYTES = 10 * 1024 * 1024;

    /** @var list<string> */
    private array $allowedMediaTypes;

    /** @param list<string> $allowedMediaTypes */
    public function __construct(
        array $allowedMediaTypes = [
            'image/png',
            'image/jpeg',
            'application/pdf',
            'text/plain',
            'text/csv',
        ],
        public int $maxBytes = self::DEFAULT_MAX_BYTES,
    ) {
        $normalized = array_values(array_unique($allowedMediaTypes));
        sort($normalized, SORT_STRING);
        if ($normalized === [] || $maxBytes < 1 || $maxBytes > self::DEFAULT_MAX_BYTES) {
            throw FileMediaException::uploadInvalid('The file upload policy is invalid.');
        }
        foreach ($normalized as $mediaType) {
            if (!in_array($mediaType, self::defaultMediaTypes(), true)) {
                throw FileMediaException::uploadInvalid('The file upload policy cannot broaden the default allow-list.');
            }
        }
        $this->allowedMediaTypes = $normalized;
    }

    /** @return list<string> */
    public static function defaultMediaTypes(): array
    {
        return ['image/png', 'image/jpeg', 'application/pdf', 'text/plain', 'text/csv'];
    }

    public function inspect(string $sourcePath, string $originalName): UploadDescriptor
    {
        if ($sourcePath === '' || is_link($sourcePath) || !is_file($sourcePath) || !is_readable($sourcePath)) {
            throw FileMediaException::uploadInvalid();
        }
        $size = filesize($sourcePath);
        if (!is_int($size) || $size < 1) {
            throw FileMediaException::uploadInvalid('The uploaded file must not be empty.');
        }
        if ($size > $this->maxBytes) {
            throw FileMediaException::tooLarge();
        }
        $mediaType = (new finfo(FILEINFO_MIME_TYPE))->file($sourcePath);
        if (!is_string($mediaType) || !in_array($mediaType, $this->allowedMediaTypes, true)) {
            throw FileMediaException::mediaTypeDenied();
        }
        $sha256 = hash_file('sha256', $sourcePath);
        if (!is_string($sha256)) {
            throw FileMediaException::uploadInvalid();
        }

        return new UploadDescriptor(
            $sourcePath,
            self::normalizeOriginalName($originalName),
            $mediaType,
            $size,
            $sha256,
        );
    }

    public static function normalizeOriginalName(string $name): string
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw FileMediaException::uploadInvalid('The uploaded file name is invalid.');
        }
        $name = str_replace('\\', '/', $name);
        $name = basename($name);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = trim($name);
        $characterCount = preg_match_all('/./us', $name);
        if ($name === '' || $name === '.' || $name === '..' || $characterCount === false || $characterCount > 255) {
            throw FileMediaException::uploadInvalid('The uploaded file name is invalid.');
        }

        return $name;
    }
}
