<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport\Application;

use RuntimeException;

final class ImportExportException extends RuntimeException
{
    private function __construct(public readonly string $problemCode)
    {
        parent::__construct($problemCode);
    }

    public static function invalid(): self
    {
        return new self('IMPORT_EXPORT_INVALID');
    }
    public static function denied(): self
    {
        return new self('IMPORT_EXPORT_PERMISSION_DENIED');
    }
    public static function notFound(): self
    {
        return new self('IMPORT_EXPORT_NOT_FOUND');
    }
    public static function providerUnavailable(): self
    {
        return new self('IMPORT_EXPORT_PROVIDER_UNAVAILABLE');
    }
    public static function fileUnavailable(): self
    {
        return new self('IMPORT_EXPORT_FILE_UNAVAILABLE');
    }
    public static function schemaMismatch(): self
    {
        return new self('IMPORT_EXPORT_SCHEMA_MISMATCH');
    }
    public static function conflict(): self
    {
        return new self('IMPORT_EXPORT_IDEMPOTENCY_CONFLICT');
    }
    public static function stateConflict(): self
    {
        return new self('IMPORT_EXPORT_STATE_CONFLICT');
    }
    public static function limitExceeded(): self
    {
        return new self('IMPORT_EXPORT_LIMIT_EXCEEDED');
    }
    public static function internal(): self
    {
        return new self('IMPORT_EXPORT_INTERNAL_ERROR');
    }
}
