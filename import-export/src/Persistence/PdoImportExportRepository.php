<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use PDOException;
use PeanutAdmin\ImportExport\Application\ImportExportException;
use PeanutAdmin\ImportExport\Application\OperationRecord;
use PeanutAdmin\ImportExport\Contract\RowIssue;
use Throwable;

final readonly class PdoImportExportRepository
{
    public function __construct(private PDO $pdo) {}

    public function transaction(callable $operation): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if ($owns) {
            $this->begin();
        }
        try {
            $result = $operation();
            if ($owns) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string, string> $mapping */
    public function create(
        int $tenantId,
        int $memberId,
        string $operationKey,
        string $providerKey,
        string $direction,
        ?string $inputFileKey,
        string $schemaRevision,
        array $mapping,
        string $idempotencyKeyHash,
        string $requestHash,
        int $retentionDays,
    ): OperationRecord {
        return $this->transaction(function () use ($tenantId, $memberId, $operationKey, $providerKey, $direction, $inputFileKey, $schemaRevision, $mapping, $idempotencyKeyHash, $requestHash, $retentionDays): OperationRecord {
            $created = false;
            $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_import_export_operation (
  operation_key, tenant_id, created_by_member_id, provider_key, direction,
  input_file_key, schema_revision, mapping_json, idempotency_key_hash,
  request_hash, retention_until, created_at, updated_at
) VALUES (
  :operation_key, :tenant_id, :member_id, :provider_key, :direction,
  :input_file_key, :schema_revision, :mapping_json, :idempotency_hash,
  :request_hash, TIMESTAMPADD(DAY, :retention_days, UTC_TIMESTAMP(3)),
  UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
)
SQL);
            try {
                $statement->execute([
                    'operation_key' => $operationKey,
                    'tenant_id' => $tenantId,
                    'member_id' => $memberId,
                    'provider_key' => $providerKey,
                    'direction' => $direction,
                    'input_file_key' => $inputFileKey,
                    'schema_revision' => $schemaRevision,
                    'mapping_json' => $this->json($mapping),
                    'idempotency_hash' => $idempotencyKeyHash,
                    'request_hash' => $requestHash,
                    'retention_days' => $retentionDays,
                ]);
                $id = (int) $this->pdo->lastInsertId();
                $created = true;
            } catch (PDOException $exception) {
                if ($exception->getCode() !== '23000') {
                    throw $exception;
                }
                $existing = $this->byIdempotency($tenantId, $memberId, $direction, $providerKey, $idempotencyKeyHash, true);
                if ($existing === null || !hash_equals((string) $existing['request_hash'], $requestHash)) {
                    throw ImportExportException::conflict();
                }
                $id = (int) $existing['id'];
            }
            $row = $this->byId($tenantId, $id, true);
            if ($row === null || !hash_equals((string) $row['request_hash'], $requestHash)) {
                throw ImportExportException::conflict();
            }
            if (!$created && !hash_equals((string) $row['schema_revision'], $schemaRevision)) {
                throw ImportExportException::schemaMismatch();
            }
            return $this->map($row);
        });
    }

    public function attachJob(int $tenantId, string $operationKey, string $jobKey): OperationRecord
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_import_export_operation
SET task_job_key = :job_key, revision = revision + 1, updated_at = UTC_TIMESTAMP(3)
WHERE tenant_id = :tenant_id AND operation_key = :operation_key AND status = 'queued'
  AND (task_job_key IS NULL OR task_job_key = :job_key_check)
SQL);
        $statement->execute(['job_key' => $jobKey, 'job_key_check' => $jobKey, 'tenant_id' => $tenantId, 'operation_key' => $operationKey]);
        $row = $this->byKey($tenantId, $operationKey, true);
        if ($row === null) {
            throw ImportExportException::notFound();
        }
        if (!is_string($row['task_job_key']) || !hash_equals($jobKey, $row['task_job_key'])) {
            throw ImportExportException::stateConflict();
        }
        return $this->map($row);
    }

    /** @return array{items: list<OperationRecord>, page: int, page_size: int, total: int} */
    public function list(int $tenantId, string $status, int $page, int $pageSize): array
    {
        if ($page < 1 || $page > 1_000_000 || $pageSize < 1 || $pageSize > 100) {
            throw ImportExportException::invalid();
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM pa_import_export_operation WHERE tenant_id = :tenant_id AND status = :status');
        $count->execute(['tenant_id' => $tenantId, 'status' => $status]);
        $statement = $this->pdo->prepare('SELECT * FROM pa_import_export_operation WHERE tenant_id = :tenant_id AND status = :status ORDER BY id DESC LIMIT :limit OFFSET :offset');
        $statement->bindValue('tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue('status', $status);
        $statement->bindValue('limit', $pageSize, PDO::PARAM_INT);
        $statement->bindValue('offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $statement->execute();
        return ['items' => array_map($this->map(...), $statement->fetchAll(PDO::FETCH_ASSOC)), 'page' => $page, 'page_size' => $pageSize, 'total' => (int) $count->fetchColumn()];
    }

    public function get(int $tenantId, string $operationKey): OperationRecord
    {
        $row = $this->byKey($tenantId, $operationKey, false);
        if ($row === null) {
            throw ImportExportException::notFound();
        }
        return $this->map($row);
    }

    public function requestCancel(int $tenantId, string $operationKey, int $revision): OperationRecord
    {
        return $this->transaction(function () use ($tenantId, $operationKey, $revision): OperationRecord {
            $row = $this->byKey($tenantId, $operationKey, true);
            if ($row === null) {
                throw ImportExportException::notFound();
            }
            $next = $row['status'] === 'queued' ? 'cancelled' : 'cancel_requested';
            if (!in_array($row['status'], ['queued', 'running'], true) || (int) $row['revision'] !== $revision) {
                throw ImportExportException::stateConflict();
            }
            $statement = $this->pdo->prepare("UPDATE pa_import_export_operation SET status = :status, completed_at = IF(:completion_status = 'cancelled', UTC_TIMESTAMP(3), NULL), revision = revision + 1, updated_at = UTC_TIMESTAMP(3) WHERE id = :id AND tenant_id = :tenant_id AND revision = :revision");
            $statement->execute(['status' => $next, 'completion_status' => $next, 'id' => $row['id'], 'tenant_id' => $tenantId, 'revision' => $revision]);
            if ($statement->rowCount() !== 1) {
                throw ImportExportException::stateConflict();
            }
            return $this->map($this->byId($tenantId, (int) $row['id'], true) ?? throw ImportExportException::internal());
        });
    }

    public function beginAttempt(int $tenantId, string $operationKey, string $jobKey, int $attempt): OperationRecord
    {
        return $this->transaction(function () use ($tenantId, $operationKey, $jobKey, $attempt): OperationRecord {
            $row = $this->byKey($tenantId, $operationKey, true);
            if ($row === null) {
                throw ImportExportException::notFound();
            }
            if (!is_string($row['task_job_key']) || !hash_equals($jobKey, $row['task_job_key']) || $attempt < 1 || $attempt > 10 || $attempt <= (int) $row['attempt_number'] || !in_array($row['status'], ['queued', 'running'], true)) {
                throw ImportExportException::stateConflict();
            }
            $statement = $this->pdo->prepare("UPDATE pa_import_export_operation SET status = 'running', attempt_number = :attempt, last_error_code = NULL, revision = revision + 1, updated_at = UTC_TIMESTAMP(3) WHERE id = :id AND tenant_id = :tenant_id AND attempt_number < :attempt_fence AND status IN ('queued','running')");
            $statement->execute(['attempt' => $attempt, 'attempt_fence' => $attempt, 'id' => $row['id'], 'tenant_id' => $tenantId]);
            if ($statement->rowCount() !== 1) {
                throw ImportExportException::stateConflict();
            }
            return $this->map($this->byId($tenantId, (int) $row['id'], true) ?? throw ImportExportException::internal());
        });
    }

    public function checkpointProgressOrCancel(int $tenantId, int $operationId, string $jobKey, int $attempt, int $processed, int $accepted, int $rejected): OperationRecord
    {
        if ($processed < 0 || $processed > 100000 || $accepted < 0 || $rejected < 0 || $accepted + $rejected > $processed) {
            throw ImportExportException::internal();
        }
        return $this->transaction(function () use ($tenantId, $operationId, $jobKey, $attempt, $processed, $accepted, $rejected): OperationRecord {
            $row = $this->byId($tenantId, $operationId, true);
            if ($row === null || !is_string($row['task_job_key']) || !hash_equals($jobKey, $row['task_job_key'])
                || (int) $row['attempt_number'] !== $attempt || !in_array($row['status'], ['running', 'cancel_requested'], true)
            ) {
                throw ImportExportException::stateConflict();
            }
            $cancelled = $row['status'] === 'cancel_requested';
            if (!$cancelled && (int) $row['processed_rows'] === $processed
                && (int) $row['accepted_rows'] === $accepted && (int) $row['rejected_rows'] === $rejected
            ) {
                return $this->map($row);
            }
            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_import_export_operation
SET status = :status,
    processed_rows = :processed,
    accepted_rows = :accepted,
    rejected_rows = :rejected,
    total_rows = IF(:cancelled = 1, :processed_total, total_rows),
    result_file_key = IF(:cancelled_result_file = 1, NULL, result_file_key),
    error_file_key = IF(:cancelled_error_file = 1, NULL, error_file_key),
    last_error_code = IF(:cancelled_error = 1, NULL, last_error_code),
    completed_at = IF(:cancelled_completion = 1, UTC_TIMESTAMP(3), completed_at),
    revision = revision + 1,
    updated_at = UTC_TIMESTAMP(3)
WHERE id = :id AND tenant_id = :tenant_id AND task_job_key = :job_key
  AND attempt_number = :attempt AND status = :expected_status
SQL);
            $statement->execute([
                'status' => $cancelled ? 'cancelled' : 'running',
                'processed' => $processed,
                'accepted' => $accepted,
                'rejected' => $rejected,
                'cancelled' => $cancelled ? 1 : 0,
                'processed_total' => $processed,
                'cancelled_result_file' => $cancelled ? 1 : 0,
                'cancelled_error_file' => $cancelled ? 1 : 0,
                'cancelled_error' => $cancelled ? 1 : 0,
                'cancelled_completion' => $cancelled ? 1 : 0,
                'id' => $operationId,
                'tenant_id' => $tenantId,
                'job_key' => $jobKey,
                'attempt' => $attempt,
                'expected_status' => $row['status'],
            ]);
            if ($statement->rowCount() !== 1) {
                throw ImportExportException::stateConflict();
            }
            return $this->map($this->byId($tenantId, $operationId, true) ?? throw ImportExportException::internal());
        });
    }

    public function addRowIssue(int $tenantId, int $operationId, int $rowNumber, RowIssue $issue): void
    {
        $statement = $this->pdo->prepare('INSERT IGNORE INTO pa_import_export_row_error (`tenant_id`, `operation_id`, `row_number`, `column_key`, `error_code`, `occurred_at`) VALUES (:tenant_id, :operation_id, :row_number, :column_key, :error_code, UTC_TIMESTAMP(3))');
        $statement->execute(['tenant_id' => $tenantId, 'operation_id' => $operationId, 'row_number' => $rowNumber, 'column_key' => $issue->columnKey, 'error_code' => $issue->code]);
    }

    /** @return list<array{row_number: int, column_key: string|null, error_code: string}> */
    public function rowIssues(int $tenantId, int $operationId, int $limit = 10000): array
    {
        if ($limit < 1 || $limit > 10000) {
            throw ImportExportException::invalid();
        }
        $statement = $this->pdo->prepare('SELECT `row_number`, `column_key`, `error_code` FROM pa_import_export_row_error WHERE `tenant_id` = :tenant_id AND `operation_id` = :operation_id ORDER BY `row_number`, `id` LIMIT :limit');
        $statement->bindValue('tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue('operation_id', $operationId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return array_map(static fn(array $row): array => ['row_number' => (int) $row['row_number'], 'column_key' => is_string($row['column_key']) ? $row['column_key'] : null, 'error_code' => (string) $row['error_code']], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function finish(int $tenantId, int $operationId, string $jobKey, int $attempt, string $status, ?string $resultFileKey, ?string $errorFileKey, int $totalRows, ?string $errorCode = null): OperationRecord
    {
        if (!in_array($status, ['succeeded', 'failed', 'cancelled'], true) || $totalRows < 0 || $totalRows > 100000 || ($status === 'succeeded' && $errorCode !== null) || ($status === 'failed' && preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', (string) $errorCode) !== 1)) {
            throw ImportExportException::internal();
        }
        return $this->transaction(function () use ($tenantId, $operationId, $jobKey, $attempt, $status, $resultFileKey, $errorFileKey, $totalRows, $errorCode): OperationRecord {
            $row = $this->byId($tenantId, $operationId, true);
            if ($row === null || !is_string($row['task_job_key']) || !hash_equals($jobKey, $row['task_job_key'])
                || (int) $row['attempt_number'] !== $attempt || !in_array($row['status'], ['running', 'cancel_requested'], true)
            ) {
                throw ImportExportException::stateConflict();
            }
            $cancelled = $row['status'] === 'cancel_requested';
            $statement = $this->pdo->prepare("UPDATE pa_import_export_operation SET status = :status, result_file_key = :result_file_key, error_file_key = :error_file_key, total_rows = :total_rows, last_error_code = :error_code, completed_at = UTC_TIMESTAMP(3), revision = revision + 1, updated_at = UTC_TIMESTAMP(3) WHERE id = :id AND tenant_id = :tenant_id AND task_job_key = :job_key AND attempt_number = :attempt AND status = :expected_status");
            $statement->execute([
                'status' => $cancelled ? 'cancelled' : $status,
                'result_file_key' => $cancelled ? null : $resultFileKey,
                'error_file_key' => $cancelled ? null : $errorFileKey,
                'total_rows' => $totalRows,
                'error_code' => $cancelled ? null : $errorCode,
                'id' => $operationId,
                'tenant_id' => $tenantId,
                'job_key' => $jobKey,
                'attempt' => $attempt,
                'expected_status' => $row['status'],
            ]);
            if ($statement->rowCount() !== 1) {
                throw ImportExportException::stateConflict();
            }
            return $this->map($this->byId($tenantId, $operationId, true) ?? throw ImportExportException::internal());
        });
    }

    public function expireDue(int $limit = 100): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw ImportExportException::invalid();
        }
        $statement = $this->pdo->prepare("UPDATE pa_import_export_operation SET status = 'expired', result_file_key = NULL, error_file_key = NULL, revision = revision + 1, updated_at = UTC_TIMESTAMP(3) WHERE status IN ('succeeded','failed','cancelled') AND retention_until <= UTC_TIMESTAMP(3) ORDER BY id LIMIT :limit");
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->rowCount();
    }

    /** @return array<string, mixed>|null */
    private function byIdempotency(int $tenantId, int $memberId, string $direction, string $providerKey, string $hash, bool $lock): ?array
    {
        $sql = 'SELECT * FROM pa_import_export_operation WHERE tenant_id = :tenant_id AND created_by_member_id = :member_id AND direction = :direction AND provider_key = :provider_key AND idempotency_key_hash = :hash' . ($lock ? ' FOR UPDATE' : '');
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['tenant_id' => $tenantId, 'member_id' => $memberId, 'direction' => $direction, 'provider_key' => $providerKey, 'hash' => $hash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function byKey(int $tenantId, string $operationKey, bool $lock): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pa_import_export_operation WHERE tenant_id = :tenant_id AND operation_key = :operation_key' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute(['tenant_id' => $tenantId, 'operation_key' => $operationKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function byId(int $tenantId, int $id, bool $lock): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pa_import_export_operation WHERE tenant_id = :tenant_id AND id = :id' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): OperationRecord
    {
        try {
            $mapping = json_decode((string) $row['mapping_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ImportExportException::internal();
        }
        if (!is_array($mapping)) {
            throw ImportExportException::internal();
        }
        foreach ($mapping as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                throw ImportExportException::internal();
            }
        }
        return new OperationRecord(
            (int) $row['id'],
            (string) $row['operation_key'],
            (int) $row['tenant_id'],
            (int) $row['created_by_member_id'],
            (string) $row['provider_key'],
            (string) $row['direction'],
            (string) $row['status'],
            is_string($row['input_file_key']) ? $row['input_file_key'] : null,
            is_string($row['result_file_key']) ? $row['result_file_key'] : null,
            is_string($row['error_file_key']) ? $row['error_file_key'] : null,
            is_string($row['task_job_key']) ? $row['task_job_key'] : null,
            (string) $row['schema_revision'],
            $mapping,
            (int) $row['processed_rows'],
            (int) $row['accepted_rows'],
            (int) $row['rejected_rows'],
            (int) $row['total_rows'],
            (int) $row['attempt_number'],
            (int) $row['revision'],
            is_string($row['last_error_code']) ? $row['last_error_code'] : null,
            $this->time((string) $row['retention_until']),
            $this->time((string) $row['created_at']),
            $this->time((string) $row['updated_at']),
            is_string($row['completed_at']) ? $this->time($row['completed_at']) : null,
        );
    }

    private function json(array $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw ImportExportException::invalid();
        }
    }

    private function time(string $value): string
    {
        $time = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value, new DateTimeZone('UTC'));
        if (!$time instanceof DateTimeImmutable) {
            throw ImportExportException::internal();
        }
        return $time->format('Y-m-d\TH:i:s.v\Z');
    }

    private function begin(): void
    {
        try {
            if (!$this->pdo->beginTransaction()) {
                throw ImportExportException::internal();
            }
        } catch (PDOException) {
            throw ImportExportException::internal();
        }
    }
}
