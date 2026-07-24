<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport\Contract;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

interface DataProvider
{
    public function key(): string;
    public function schema(): SchemaDefinition;

    /** @param array<string, string|null> $row @return list<RowIssue> */
    public function validateImport(AuthorizedOperationContext $context, array $row): array;

    /** @param array<string, string|null> $row */
    public function importRow(AuthorizedOperationContext $context, array $row, string $idempotencyKey): void;

    public function exportBatch(AuthorizedOperationContext $context, ?string $cursor, int $limit): ExportBatch;
}
