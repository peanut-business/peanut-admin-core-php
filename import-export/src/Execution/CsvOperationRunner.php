<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport\Execution;

use PeanutAdmin\ImportExport\Application\ImportExportException;
use PeanutAdmin\ImportExport\Application\OperationRecord;
use PeanutAdmin\ImportExport\Contract\DataProviderRegistry;
use PeanutAdmin\ImportExport\Contract\RowIssue;
use PeanutAdmin\ImportExport\File\FileMediaGateway;
use PeanutAdmin\ImportExport\Persistence\PdoImportExportRepository;
use PeanutAdmin\Kernel\Audit\AuditRepository;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\TaskJob\Execution\RetryableTaskException;
use Throwable;

final readonly class CsvOperationRunner
{
    private const MAX_ROWS = 100000;
    private const MAX_BYTES = 16777216;

    public function __construct(
        private PdoImportExportRepository $repository,
        private DataProviderRegistry $providers,
        private FileMediaGateway $files,
        private AuditRepository $audit,
    ) {}

    public function run(AuthorizedOperationContext $context, string $operationKey, string $jobKey, int $attempt): OperationRecord
    {
        $operation = $this->repository->beginAttempt($context->tenantContext->tenantId, $operationKey, $jobKey, $attempt);
        try {
            $this->audit($context, $operation, 'started', ['attempt' => $attempt]);
            $provider = $this->providers->require($operation->providerKey);
            if (!hash_equals($operation->schemaRevision, $provider->schema()->revision)) {
                $result = $this->repository->finish($operation->tenantId, $operation->id, $jobKey, $attempt, 'failed', null, null, 0, 'IMPORT_EXPORT_SCHEMA_MISMATCH');
                $this->audit($context, $result, 'failed', ['error_code' => 'IMPORT_EXPORT_SCHEMA_MISMATCH']);
                return $result;
            }
            $result = $operation->direction === 'import'
                ? $this->runImport($context, $operation, $jobKey, $attempt)
                : $this->runExport($context, $operation, $jobKey, $attempt);
            $this->audit($context, $result, $result->status, ['processed_rows' => $result->processedRows, 'accepted_rows' => $result->acceptedRows, 'rejected_rows' => $result->rejectedRows]);
            return $result;
        } catch (RetryableTaskException $exception) {
            $current = $this->repository->get($operation->tenantId, $operation->operationKey);
            $checkpoint = $this->repository->checkpointProgressOrCancel(
                $operation->tenantId,
                $operation->id,
                $jobKey,
                $attempt,
                $current->processedRows,
                $current->acceptedRows,
                $current->rejectedRows,
            );
            if ($checkpoint->status === 'cancelled') {
                $this->audit($context, $checkpoint, 'cancelled', ['processed_rows' => $checkpoint->processedRows]);
                return $checkpoint;
            }
            if ($attempt >= 3) {
                $failed = $this->repository->finish($operation->tenantId, $operation->id, $jobKey, $attempt, 'failed', null, null, $current->processedRows, $exception->safeCode);
                $this->audit($context, $failed, $failed->status, $failed->status === 'cancelled' ? ['processed_rows' => $failed->processedRows] : ['error_code' => $exception->safeCode]);
                if ($failed->status === 'cancelled') {
                    return $failed;
                }
            }
            throw $exception;
        } catch (ImportExportException $exception) {
            if (!in_array($exception->problemCode, ['IMPORT_EXPORT_STATE_CONFLICT', 'IMPORT_EXPORT_PERMISSION_DENIED'], true)) {
                $current = $this->repository->get($operation->tenantId, $operation->operationKey);
                $failed = $this->repository->finish($operation->tenantId, $operation->id, $jobKey, $attempt, 'failed', null, null, $current->processedRows, $exception->problemCode);
                $this->audit($context, $failed, $failed->status, $failed->status === 'cancelled' ? ['processed_rows' => $failed->processedRows] : ['error_code' => $exception->problemCode]);
                if ($failed->status === 'cancelled') {
                    return $failed;
                }
            }
            throw $exception;
        } catch (Throwable $exception) {
            $current = $this->repository->get($operation->tenantId, $operation->operationKey);
            $failed = $this->repository->finish($operation->tenantId, $operation->id, $jobKey, $attempt, 'failed', null, null, $current->processedRows, 'IMPORT_EXPORT_INTERNAL_ERROR');
            $this->audit($context, $failed, $failed->status, $failed->status === 'cancelled' ? ['processed_rows' => $failed->processedRows] : ['error_code' => 'IMPORT_EXPORT_INTERNAL_ERROR']);
            if ($failed->status === 'cancelled') {
                return $failed;
            }
            throw $exception;
        }
    }

    private function runImport(AuthorizedOperationContext $context, OperationRecord $operation, string $jobKey, int $attempt): OperationRecord
    {
        if ($operation->inputFileKey === null) {
            throw ImportExportException::internal();
        }
        $stream = $this->files->openCsvInput($context, $operation->inputFileKey);
        if (!is_resource($stream)) {
            throw ImportExportException::fileUnavailable();
        }
        $provider = $this->providers->require($operation->providerKey);
        $schema = $provider->schema();
        $headings = fgetcsv($stream, 1048577, ',', '"', '');
        if (!is_array($headings) || $headings === [] || count($headings) > 100) {
            throw ImportExportException::schemaMismatch();
        }
        $headings = array_map(static fn(mixed $value): string => is_string($value) && preg_match('//u', $value) === 1 ? $value : throw ImportExportException::schemaMismatch(), $headings);
        if (count(array_unique($headings, SORT_STRING)) !== count($headings) || array_diff(array_keys($operation->mapping), $headings) !== []) {
            throw ImportExportException::schemaMismatch();
        }
        $processed = $accepted = $rejected = $issueCount = 0;
        while (($values = fgetcsv($stream, 1048577, ',', '"', '')) !== false) {
            $checkpoint = $this->repository->checkpointProgressOrCancel($operation->tenantId, $operation->id, $jobKey, $attempt, $processed, $accepted, $rejected);
            if ($checkpoint->status === 'cancelled') {
                return $checkpoint;
            }
            ++$processed;
            $rowNumber = $processed + 1;
            if ($processed > self::MAX_ROWS) {
                throw ImportExportException::limitExceeded();
            }
            $normalized = $schema->normalizeImportRow($values, $headings, $operation->mapping);
            $rowBytes = array_sum(array_map(static fn(mixed $value): int => is_string($value) ? strlen($value) : 0, $values));
            if ($rowBytes > 1048576) {
                $normalized['issues'][] = new RowIssue('IMPORT_ROW_TOO_LARGE');
            }
            $issues = $normalized['issues'];
            if ($issues === []) {
                $providerIssues = $provider->validateImport($context, $normalized['row']);
                foreach ($providerIssues as $issue) {
                    if (!$issue instanceof RowIssue) {
                        throw ImportExportException::internal();
                    }
                    $issues[] = $issue;
                }
            }
            if ($issues !== []) {
                ++$rejected;
                $issueCount += count($issues);
                if ($issueCount > 10000) {
                    throw ImportExportException::limitExceeded();
                }
                foreach ($issues as $issue) {
                    $this->repository->addRowIssue($operation->tenantId, $operation->id, $rowNumber, $issue);
                }
            } else {
                $provider->importRow($context, $normalized['row'], $operation->operationKey . ':row:' . $rowNumber);
                ++$accepted;
            }
            $checkpoint = $this->repository->checkpointProgressOrCancel($operation->tenantId, $operation->id, $jobKey, $attempt, $processed, $accepted, $rejected);
            if ($checkpoint->status === 'cancelled') {
                return $checkpoint;
            }
            $this->auditProgress($context, $checkpoint);
        }
        $errorFile = $rejected > 0 ? $this->storeErrorReport($context, $operation) : null;
        return $this->repository->finish($operation->tenantId, $operation->id, $jobKey, $attempt, 'succeeded', null, $errorFile, $processed);
    }

    private function runExport(AuthorizedOperationContext $context, OperationRecord $operation, string $jobKey, int $attempt): OperationRecord
    {
        $provider = $this->providers->require($operation->providerKey);
        $schema = $provider->schema();
        $stream = fopen('php://temp/maxmemory:' . self::MAX_BYTES, 'w+b');
        if (!is_resource($stream)) {
            throw ImportExportException::internal();
        }
        fputcsv($stream, array_map(static fn($column): string => self::csvSafe($column->heading), $schema->exportColumns()), ',', '"', '');
        $cursor = null;
        $processed = 0;
        do {
            $checkpoint = $this->repository->checkpointProgressOrCancel($operation->tenantId, $operation->id, $jobKey, $attempt, $processed, $processed, 0);
            if ($checkpoint->status === 'cancelled') {
                return $checkpoint;
            }
            $batch = $provider->exportBatch($context, $cursor, 500);
            if (count($batch->rows) > 500 || ($batch->rows === [] && $batch->nextCursor !== null) || ($batch->nextCursor !== null && $batch->nextCursor === $cursor)) {
                throw ImportExportException::internal();
            }
            foreach ($batch->rows as $row) {
                ++$processed;
                if ($processed > self::MAX_ROWS) {
                    throw ImportExportException::limitExceeded();
                }
                fputcsv($stream, array_map(self::csvSafe(...), $schema->exportValues($row)), ',', '"', '');
                $size = ftell($stream);
                if (!is_int($size) || $size > self::MAX_BYTES) {
                    throw ImportExportException::limitExceeded();
                }
            }
            $checkpoint = $this->repository->checkpointProgressOrCancel($operation->tenantId, $operation->id, $jobKey, $attempt, $processed, $processed, 0);
            if ($checkpoint->status === 'cancelled') {
                return $checkpoint;
            }
            $this->auditProgress($context, $checkpoint);
            $cursor = $batch->nextCursor;
        } while ($cursor !== null);
        rewind($stream);
        $fileKey = $this->files->storePrivateCsv($context, $operation->operationKey, 'result', $operation->providerKey . '-export.csv', $stream);
        self::assertFileKey($fileKey);
        return $this->repository->finish($operation->tenantId, $operation->id, $jobKey, $attempt, 'succeeded', $fileKey, null, $processed);
    }

    private function storeErrorReport(AuthorizedOperationContext $context, OperationRecord $operation): string
    {
        $stream = fopen('php://temp/maxmemory:' . self::MAX_BYTES, 'w+b');
        if (!is_resource($stream)) {
            throw ImportExportException::internal();
        }
        fputcsv($stream, ['row_number', 'column_key', 'error_code'], ',', '"', '');
        foreach ($this->repository->rowIssues($operation->tenantId, $operation->id) as $issue) {
            fputcsv($stream, [$issue['row_number'], $issue['column_key'] ?? '', $issue['error_code']], ',', '"', '');
        }
        rewind($stream);
        $fileKey = $this->files->storePrivateCsv($context, $operation->operationKey, 'errors', 'import-errors.csv', $stream);
        self::assertFileKey($fileKey);
        return $fileKey;
    }

    private static function assertFileKey(string $key): void
    {
        if (preg_match('/^file_[0-9a-f]{32}$/D', $key) !== 1) {
            throw ImportExportException::fileUnavailable();
        }
    }

    private static function csvSafe(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw ImportExportException::schemaMismatch();
        }
        return preg_match('/^(?:\xEF\xBB\xBF)?[\x09\x0B\x0C\x0D\x20]*[=+@-]/', $value) === 1 ? "'" . $value : $value;
    }

    private function auditProgress(AuthorizedOperationContext $context, OperationRecord $operation): void
    {
        if ($operation->processedRows !== 1 && $operation->processedRows % 1000 !== 0) {
            return;
        }
        $this->audit($context, $operation, 'progress', [
            'processed_rows' => $operation->processedRows,
            'accepted_rows' => $operation->acceptedRows,
            'rejected_rows' => $operation->rejectedRows,
        ]);
    }

    /** @param array<string, int|string> $metadata */
    private function audit(AuthorizedOperationContext $context, OperationRecord $operation, string $event, array $metadata): void
    {
        $this->audit->appendTenantMember(
            $context->tenantContext,
            'tenant.import_export.' . $event,
            'peanut.import-export.execute',
            'import_export_operation',
            $operation->operationKey,
            targetCount: 1,
            metadata: ['direction' => $operation->direction, 'provider_key' => $operation->providerKey, 'revision' => $operation->revision] + $metadata,
        );
    }
}
