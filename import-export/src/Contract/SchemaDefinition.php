<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport\Contract;

use PeanutAdmin\ImportExport\Application\ImportExportException;

final readonly class SchemaDefinition
{
    /** @var array<string, ColumnDefinition> */
    private array $byKey;

    /** @param list<ColumnDefinition> $columns */
    public function __construct(public string $revision, public array $columns)
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $revision) !== 1 || $columns === [] || count($columns) > 100) {
            throw ImportExportException::invalid();
        }
        $byKey = [];
        $headings = [];
        foreach ($columns as $column) {
            if (isset($byKey[$column->key]) || isset($headings[$column->heading])) {
                throw ImportExportException::invalid();
            }
            $byKey[$column->key] = $column;
            $headings[$column->heading] = true;
        }
        $this->byKey = $byKey;
    }

    /** @param array<string, string> $mapping @return array<string, string> */
    public function validateImportMapping(array $mapping): array
    {
        if ($mapping === [] || count($mapping) > 100) {
            throw ImportExportException::invalid();
        }
        $targets = [];
        foreach ($mapping as $source => $target) {
            if (!is_string($source) || preg_match('//u', $source) !== 1 || trim($source) !== $source || $source === '' || strlen($source) > 120
                || !isset($this->byKey[$target]) || !$this->byKey[$target]->importable || isset($targets[$target])) {
                throw ImportExportException::schemaMismatch();
            }
            $targets[$target] = true;
        }
        foreach ($this->columns as $column) {
            if ($column->importable && $column->requiredOnImport && !isset($targets[$column->key])) {
                throw ImportExportException::schemaMismatch();
            }
        }
        ksort($mapping, SORT_STRING);
        return $mapping;
    }

    /** @return list<ColumnDefinition> */
    public function exportColumns(): array
    {
        return array_values(array_filter($this->columns, static fn(ColumnDefinition $column): bool => $column->exportable));
    }

    /** @param list<string|null> $values @param list<string> $headings @param array<string, string> $mapping
     *  @return array{row: array<string, string|null>, issues: list<RowIssue>}
     */
    public function normalizeImportRow(array $values, array $headings, array $mapping): array
    {
        if (count($values) !== count($headings)) {
            return ['row' => [], 'issues' => [new RowIssue('IMPORT_ROW_COLUMN_COUNT')]];
        }
        $row = [];
        $issues = [];
        foreach ($headings as $index => $heading) {
            $value = $values[$index];
            if ($value !== null && (!is_string($value) || preg_match('//u', $value) !== 1)) {
                throw ImportExportException::schemaMismatch();
            }
            if (!isset($mapping[$heading])) {
                continue;
            }
            $target = $mapping[$heading];
            $column = $this->byKey[$target] ?? throw ImportExportException::schemaMismatch();
            if ($value !== null && strlen($value) > $column->maxBytes) {
                $issues[] = new RowIssue('IMPORT_VALUE_TOO_LONG', $target);
                continue;
            }
            $row[$target] = $value === '' ? null : $value;
        }
        foreach ($this->columns as $column) {
            if ($column->importable && $column->requiredOnImport && (($row[$column->key] ?? null) === null)) {
                $issues[] = new RowIssue('IMPORT_VALUE_REQUIRED', $column->key);
            }
        }
        ksort($row, SORT_STRING);
        return ['row' => $row, 'issues' => $issues];
    }

    /** @param array<string, bool|int|float|string|null> $row @return list<string> */
    public function exportValues(array $row): array
    {
        $values = [];
        foreach ($this->exportColumns() as $column) {
            if (!array_key_exists($column->key, $row)) {
                throw ImportExportException::schemaMismatch();
            }
            $value = $row[$column->key];
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }
            if ($value !== null && !is_int($value) && !is_float($value) && !is_string($value)) {
                throw ImportExportException::schemaMismatch();
            }
            $text = $value === null ? '' : (string) $value;
            if (preg_match('//u', $text) !== 1) {
                throw ImportExportException::schemaMismatch();
            }
            if (strlen($text) > $column->maxBytes) {
                throw ImportExportException::limitExceeded();
            }
            $values[] = $text;
        }
        return $values;
    }
}
