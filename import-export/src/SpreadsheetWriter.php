<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

final class SpreadsheetWriter
{
    /** @param iterable<iterable<mixed>> $rows */
    public function write(string $path, iterable $rows, ?string $sheetName = null): void
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $writer = match ($extension) {
            'xlsx' => new XlsxWriter(),
            'csv' => new CsvWriter(),
            default => throw new \InvalidArgumentException('Unsupported spreadsheet format.'),
        };
        $writer->openToFile($path);
        try {
            if ($sheetName !== null && $writer instanceof XlsxWriter) {
                $writer->getCurrentSheet()->setName($sheetName);
            }
            foreach ($rows as $row) {
                $cells = [];
                foreach ($row as $value) {
                    if (is_string($value)) {
                        if ($extension === 'csv' && preg_match('/^[\s\p{Z}\x{FEFF}\x{200B}]*[=+\-@]/u', $value) === 1) {
                            $value = "'" . $value;
                        }
                        $cells[] = new StringCell($value, null);
                    } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null || $value instanceof \DateTimeInterface || $value instanceof \DateInterval) {
                        $cells[] = Cell::fromValue($value);
                    } else {
                        throw new \InvalidArgumentException('Unsupported spreadsheet cell value.');
                    }
                }
                $writer->addRow(new Row($cells));
            }
        } finally {
            $writer->close();
        }
    }
}
