<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport\Tests;

use PeanutAdmin\ImportExport\ColumnTemplate;
use PeanutAdmin\ImportExport\SpreadsheetReader;
use PeanutAdmin\ImportExport\SpreadsheetWriter;
use PHPUnit\Framework\TestCase;

final class SpreadsheetTest extends TestCase
{
    public function testXlsxPreservesUntrustedFormulaAsString(): void
    {
        $path = sys_get_temp_dir() . '/peanut-' . bin2hex(random_bytes(8)) . '.xlsx';
        try {
            (new SpreadsheetWriter())->write($path, [['=2+2', 'hello', 42]], 'Export');
            self::assertSame([['=2+2', 'hello', 42]], iterator_to_array((new SpreadsheetReader())->read($path), false));
            $zip = new \ZipArchive();
            self::assertTrue($zip->open($path));
            $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
            self::assertIsString($xml);
            self::assertStringNotContainsString('<f>', $xml);
            $zip->close();
        } finally { @unlink($path); }
    }

    public function testFormulaReadReturnsTextWithoutEvaluation(): void
    {
        $path = sys_get_temp_dir() . '/peanut-' . bin2hex(random_bytes(8)) . '.xlsx';
        try {
            $writer = new \OpenSpout\Writer\XLSX\Writer();
            $writer->openToFile($path);
            $writer->addRow(new \OpenSpout\Common\Entity\Row([
                new \OpenSpout\Common\Entity\Cell\FormulaCell('=2+2', null, 4),
            ]));
            $writer->close();
            self::assertSame([['=2+2']], iterator_to_array((new SpreadsheetReader())->read($path), false));
        } finally {
            @unlink($path);
        }
    }

    public function testCsvFormulaPrefixAndValidation(): void
    {
        $path = sys_get_temp_dir() . '/peanut-' . bin2hex(random_bytes(8)) . '.csv';
        try {
            (new SpreadsheetWriter())->write($path, [['  =1+1', "\t@x", '-2', 'safe']]);
            self::assertSame([["'  =1+1", "'\t@x", "'-2", 'safe']], iterator_to_array((new SpreadsheetReader())->read($path), false));
        } finally { @unlink($path); }
        $template = new ColumnTemplate(['name' => static fn($value) => $value ? null : 'required']);
        self::assertSame(['name' => 'required'], $template->validate([]));
    }
}
