<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport;

/** Call from the host's existing authenticated JobHandlerAdapter; rows remain lazy. */
final readonly class StreamingExport
{
    public function __construct(private SpreadsheetWriter $writer = new SpreadsheetWriter()) {}

    /** @param iterable<iterable<mixed>> $rows */
    public function execute(string $path, iterable $rows, ?string $sheetName = null): void
    {
        $this->writer->write($path, $rows, $sheetName);
    }
}
