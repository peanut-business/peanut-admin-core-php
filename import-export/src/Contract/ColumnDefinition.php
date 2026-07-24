<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport\Contract;

use PeanutAdmin\ImportExport\Application\ImportExportException;

final readonly class ColumnDefinition
{
    public function __construct(
        public string $key,
        public string $heading,
        public bool $importable = true,
        public bool $exportable = true,
        public bool $requiredOnImport = false,
        public int $maxBytes = 4096,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1 || preg_match('//u', $heading) !== 1
            || trim($heading) !== $heading || $heading === '' || strlen($heading) > 120
            || str_contains($heading, ',') || str_contains($heading, "\r") || str_contains($heading, "\n")
            || (!$importable && !$exportable) || $maxBytes < 1 || $maxBytes > 65535
        ) {
            throw ImportExportException::invalid();
        }
    }
}
