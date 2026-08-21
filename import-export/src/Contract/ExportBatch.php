<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport\Contract;

use PeanutAdmin\ImportExport\Application\ImportExportException;

final readonly class ExportBatch
{
    /** @param list<array<string, bool|int|float|string|null>> $rows */
    public function __construct(public array $rows, public ?string $nextCursor)
    {
        if (count($rows) > 500
            || ($nextCursor !== null && ($nextCursor === '' || strlen($nextCursor) > 512 || preg_match('/^[\x21-\x7e]+$/D', $nextCursor) !== 1))
        ) {
            throw ImportExportException::invalid();
        }
    }
}
