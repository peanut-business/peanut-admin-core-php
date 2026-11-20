<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport;

use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

final class SpreadsheetReader
{
    /** @return iterable<list<mixed>> */
    public function read(string $path): iterable
    {
        $reader = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'xlsx' => new XlsxReader(),
            'csv' => new CsvReader(),
            default => throw new \InvalidArgumentException('Unsupported spreadsheet format.'),
        };
        $reader->open($path);
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    yield array_values(array_map(static fn($cell) => $cell->getValue(), $row->getCells()));
                }
            }
        } finally {
            $reader->close();
        }
    }
}
