<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Application;

use RuntimeException;

final class FileMediaException extends RuntimeException
{
    private function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function uploadInvalid(string $message = 'The uploaded file is invalid.'): self
    {
        return new self('FILE_UPLOAD_INVALID', 422, $message);
    }

    public static function tooLarge(): self
    {
        return new self('FILE_TOO_LARGE', 413, 'The uploaded file exceeds the allowed size.');
    }

    public static function mediaTypeDenied(): self
    {
        return new self('FILE_MEDIA_TYPE_DENIED', 415, 'The detected file media type is not allowed.');
    }

    public static function storageUnavailable(): self
    {
        return new self('FILE_STORAGE_UNAVAILABLE', 503, 'Private file storage is unavailable.');
    }

    public static function deliveryDenied(): self
    {
        return new self('FILE_DELIVERY_DENIED', 403, 'The requested file delivery is not allowed.');
    }

    public static function deliveryInvalid(): self
    {
        return new self('FILE_DELIVERY_INVALID', 422, 'The file delivery request is invalid.');
    }

    public static function deliveryUnavailable(): self
    {
        return new self('FILE_DELIVERY_UNAVAILABLE', 503, 'File delivery is unavailable.');
    }

    public static function imageInvalid(): self
    {
        return new self('FILE_IMAGE_INVALID', 422, 'The image metadata or variant is invalid.');
    }

    public static function notFound(): self
    {
        return new self('FILE_NOT_FOUND', 404, 'The requested file is unavailable.');
    }

    public static function preconditionRequired(): self
    {
        return new self('PRECONDITION_REQUIRED', 428, 'A valid strong file revision precondition is required.');
    }

    public static function revisionConflict(): self
    {
        return new self('REVISION_CONFLICT', 409, 'The file revision has changed.');
    }

    public static function internal(): self
    {
        return new self('INTERNAL_ERROR', 500, 'The file operation could not be completed.');
    }
}
