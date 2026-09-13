<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\ImportExport\Application\ImportExportException;
use PeanutAdmin\ImportExport\Application\OperationRecord;
use PeanutAdmin\ImportExport\Contract\RowIssue;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantColumnScope;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;
use think\db\exception\PDOException;
use think\db\PDOConnection;

final readonly class ImportExportStore
{
    private TenantColumnScope $tenantScope;

    public function __construct(
        private PDOConnection $connection,
        TenantPersistenceMode $mode = TenantPersistenceMode::TenantScoped,
        ?int $instanceTenantId = null,
    ) {
        $this->tenantScope = new TenantColumnScope($mode, $instanceTenantId);
        $this->tenantScope->assertRuntimeConfigured();
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
        $this->assertStorageMode();
        $created = false;
        $sql = sprintf(
            <<<'SQL'
INSERT INTO pa_import_export_operation (
  operation_key, %screated_by_member_id, provider_key, direction,
  input_file_key, schema_revision, mapping_json, idempotency_key_hash,
  request_hash, retention_until, created_at, updated_at
) VALUES (
  :operation_key, %s:member_id, :provider_key, :direction,
  :input_file_key, :schema_revision, :mapping_json, :idempotency_hash,
  :request_hash, TIMESTAMPADD(DAY, :retention_days, UTC_TIMESTAMP(3)),
  UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
)
SQL,
            $this->tenantScope->whenTenant('tenant_id, '),
            $this->tenantScope->whenTenant(':tenant_id, '),
        );
        try {
            $this->connection->execute($sql, $this->tenantScope->bindings($tenantId, [
                'operation_key' => $operationKey,
                'member_id' => $memberId,
                'provider_key' => $providerKey,
                'direction' => $direction,
                'input_file_key' => $inputFileKey,
                'schema_revision' => $schemaRevision,
                'mapping_json' => $this->json($mapping),
                'idempotency_hash' => $idempotencyKeyHash,
                'request_hash' => $requestHash,
                'retention_days' => $retentionDays,
            ]));
            $id = $this->lastInsertId();
            $created = true;
        } catch (PDOException $exception) {
            if (!$this->isDuplicate($exception)) {
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
        return $this->map($row, $tenantId);
    }

    public function attachJob(int $tenantId, string $operationKey, string $jobKey): OperationRecord
    {
        $this->assertStorageMode();
        $this->byKey($tenantId, $operationKey, false);
        $this->connection->execute(sprintf(<<<'SQL'
UPDATE pa_import_export_operation
SET task_job_key = :job_key, revision = revision + 1, updated_at = UTC_TIMESTAMP(3)
WHERE %s
  AND (task_job_key IS NULL OR task_job_key = :job_key_check)
SQL, $this->tenantScope->where("operation_key = :operation_key AND status = 'queued'")), $this->tenantScope->bindings($tenantId, [
            'job_key' => $jobKey,
            'job_key_check' => $jobKey,
            'operation_key' => $operationKey,
        ]));
        $row = $this->byKey($tenantId, $operationKey, true);
        if ($row === null) {
            throw ImportExportException::notFound();
        }
        if (!is_string($row['task_job_key']) || !hash_equals($jobKey, $row['task_job_key'])) {
            throw ImportExportException::stateConflict();
        }
        return $this->map($row, $tenantId);
    }

    /** @return array{items: list<OperationRecord>, page: int, page_size: int, total: int} */
    public function list(int $tenantId, string $status, int $page, int $pageSize): array
    {
        $this->assertStorageMode();
        if ($page < 1 || $page > 1_000_000 || $pageSize < 1 || $pageSize > 100) {
            throw ImportExportException::invalid();
        }
        $parameters = $this->tenantScope->bindings($tenantId, ['status' => $status]);
        $count = $this->one(
            'SELECT COUNT(*) AS aggregate FROM pa_import_export_operation WHERE ' . $this->tenantScope->where('status = :status'),
            $parameters,
        );
        $rows = $this->connection->query(
            'SELECT * FROM pa_import_export_operation WHERE ' . $this->tenantScope->where('status = :status')
            . sprintf(' ORDER BY id DESC LIMIT %d OFFSET %d', $pageSize, ($page - 1) * $pageSize),
            $parameters,
        );
        return [
            'items' => array_values(array_map(
                fn(array $row): OperationRecord => $this->map($row, $tenantId),
                $rows,
            )),
            'page' => $page,
            'page_size' => $pageSize,
            'total' => (int) ($count['aggregate'] ?? 0),
        ];
    }

    public function get(int $tenantId, string $operationKey): OperationRecord
    {
        $this->assertStorageMode();
        $row = $this->byKey($tenantId, $operationKey, false);
        if ($row === null) {
            throw ImportExportException::notFound();
        }
        return $this->map($row, $tenantId);
    }

    public function resultFile(int $tenantId, string $fileKey): OperationRecord
    {
        $this->assertStorageMode();
        $row = $this->one(
            'SELECT * FROM pa_import_export_operation WHERE '
            . $this->tenantScope->where("result_file_key = :file_key AND status = 'succeeded' AND retention_until > UTC_TIMESTAMP(3)")
            . ' LIMIT 1',
            $this->tenantScope->bindings($tenantId, ['file_key' => $fileKey]),
        );
        if ($row === null) {
            throw ImportExportException::fileUnavailable();
        }
        $this->tenantScope->tenantId($row, $tenantId);
        return $this->map($row, $tenantId);
    }

    public function requestCancel(int $tenantId, string $operationKey, int $revision): OperationRecord
    {
        $this->assertStorageMode();
        $row = $this->byKey($tenantId, $operationKey, true);
        if ($row === null) {
            throw ImportExportException::notFound();
        }
        $next = $row['status'] === 'queued' ? 'cancelled' : 'cancel_requested';
        if (!in_array($row['status'], ['queued', 'running'], true) || (int) $row['revision'] !== $revision) {
            throw ImportExportException::stateConflict();
        }
        $affected = $this->connection->execute(
            "UPDATE pa_import_export_operation SET status = :status, completed_at = IF(:completion_status = 'cancelled', UTC_TIMESTAMP(3), NULL), revision = revision + 1, updated_at = UTC_TIMESTAMP(3) WHERE id = :id"
            . $this->tenantScope->andWhere() . ' AND revision = :revision',
            $this->tenantScope->bindings($tenantId, [
                'status' => $next,
                'completion_status' => $next,
                'id' => $row['id'],
                'revision' => $revision,
            ]),
        );
        if ($affected !== 1) {
            throw ImportExportException::stateConflict();
        }
        return $this->map(
            $this->byId($tenantId, (int) $row['id'], true) ?? throw ImportExportException::internal(),
            $tenantId,
        );
    }

    public function beginAttempt(int $tenantId, string $operationKey, string $jobKey, int $attempt): OperationRecord
    {
        $this->assertStorageMode();
        $row = $this->byKey($tenantId, $operationKey, true);
        if ($row === null) {
            throw ImportExportException::notFound();
        }
        if (!is_string($row['task_job_key']) || !hash_equals($jobKey, $row['task_job_key']) || $attempt < 1 || $attempt > 10 || $attempt <= (int) $row['attempt_number'] || !in_array($row['status'], ['queued', 'running'], true)) {
            throw ImportExportException::stateConflict();
        }
        $affected = $this->connection->execute(
            "UPDATE pa_import_export_operation SET status = 'running', attempt_number = :attempt, last_error_code = NULL, revision = revision + 1, updated_at = UTC_TIMESTAMP(3) WHERE id = :id"
            . $this->tenantScope->andWhere() . " AND attempt_number < :attempt_fence AND status IN ('queued','running')",
            $this->tenantScope->bindings($tenantId, [
                'attempt' => $attempt,
                'attempt_fence' => $attempt,
                'id' => $row['id'],
            ]),
        );
        if ($affected !== 1) {
            throw ImportExportException::stateConflict();
        }
        return $this->map(
            $this->byId($tenantId, (int) $row['id'], true) ?? throw ImportExportException::internal(),
            $tenantId,
        );
    }

    public function checkpointProgressOrCancel(int $tenantId, int $operationId, string $jobKey, int $attempt, int $processed, int $accepted, int $rejected): OperationRecord
    {
        $this->assertStorageMode();
        if ($processed < 0 || $processed > 100000 || $accepted < 0 || $rejected < 0 || $accepted + $rejected > $processed) {
            throw ImportExportException::internal();
        }
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
            return $this->map($row, $tenantId);
        }
        $affected = $this->connection->execute(sprintf(<<<'SQL'
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
WHERE id = :id%s AND task_job_key = :job_key
  AND attempt_number = :attempt AND status = :expected_status
SQL, $this->tenantScope->andWhere()), $this->tenantScope->bindings($tenantId, [
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
            'job_key' => $jobKey,
            'attempt' => $attempt,
            'expected_status' => $row['status'],
        ]));
        if ($affected !== 1) {
            throw ImportExportException::stateConflict();
        }
        return $this->map(
            $this->byId($tenantId, $operationId, true) ?? throw ImportExportException::internal(),
            $tenantId,
        );
    }

    public function addRowIssue(int $tenantId, int $operationId, int $rowNumber, RowIssue $issue): void
    {
        $this->assertStorageMode();
        $this->byId($tenantId, $operationId, false);
        $this->connection->execute(sprintf(
            'INSERT IGNORE INTO pa_import_export_row_error (%s`operation_id`, `row_number`, `column_key`, `error_code`, `occurred_at`) VALUES (%s:operation_id, :row_number, :column_key, :error_code, UTC_TIMESTAMP(3))',
            $this->tenantScope->whenTenant('`tenant_id`, '),
            $this->tenantScope->whenTenant(':tenant_id, '),
        ), $this->tenantScope->bindings($tenantId, [
            'operation_id' => $operationId,
            'row_number' => $rowNumber,
            'column_key' => $issue->columnKey,
            'error_code' => $issue->code,
        ]));
    }

    /** @return list<array{row_number: int, column_key: string|null, error_code: string}> */
    public function rowIssues(int $tenantId, int $operationId, int $limit = 10000): array
    {
        $this->assertStorageMode();
        if ($limit < 1 || $limit > 10000) {
            throw ImportExportException::invalid();
        }
        $this->byId($tenantId, $operationId, false);
        $rows = $this->connection->query(
            'SELECT `row_number`, `column_key`, `error_code` FROM pa_import_export_row_error WHERE '
            . $this->tenantScope->where('operation_id = :operation_id')
            . sprintf(' ORDER BY `row_number`, `id` LIMIT %d', $limit),
            $this->tenantScope->bindings($tenantId, ['operation_id' => $operationId]),
        );
        return array_values(array_map(static fn(array $row): array => ['row_number' => (int) $row['row_number'], 'column_key' => is_string($row['column_key']) ? $row['column_key'] : null, 'error_code' => (string) $row['error_code']], $rows));
    }

    public function finish(int $tenantId, int $operationId, string $jobKey, int $attempt, string $status, ?string $resultFileKey, ?string $errorFileKey, int $totalRows, ?string $errorCode = null): OperationRecord
    {
        $this->assertStorageMode();
        if (!in_array($status, ['succeeded', 'failed', 'cancelled'], true) || $totalRows < 0 || $totalRows > 100000 || ($status === 'succeeded' && $errorCode !== null) || ($status === 'failed' && preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', (string) $errorCode) !== 1)) {
            throw ImportExportException::internal();
        }
        $row = $this->byId($tenantId, $operationId, true);
        if ($row === null || !is_string($row['task_job_key']) || !hash_equals($jobKey, $row['task_job_key'])
            || (int) $row['attempt_number'] !== $attempt || !in_array($row['status'], ['running', 'cancel_requested'], true)
        ) {
            throw ImportExportException::stateConflict();
        }
        $cancelled = $row['status'] === 'cancel_requested';
        $affected = $this->connection->execute(
            'UPDATE pa_import_export_operation SET status = :status, result_file_key = :result_file_key, error_file_key = :error_file_key, total_rows = :total_rows, last_error_code = :error_code, completed_at = UTC_TIMESTAMP(3), revision = revision + 1, updated_at = UTC_TIMESTAMP(3) WHERE id = :id'
            . $this->tenantScope->andWhere()
            . ' AND task_job_key = :job_key AND attempt_number = :attempt AND status = :expected_status',
            $this->tenantScope->bindings($tenantId, [
                'status' => $cancelled ? 'cancelled' : $status,
                'result_file_key' => $cancelled ? null : $resultFileKey,
                'error_file_key' => $cancelled ? null : $errorFileKey,
                'total_rows' => $totalRows,
                'error_code' => $cancelled ? null : $errorCode,
                'id' => $operationId,
                'job_key' => $jobKey,
                'attempt' => $attempt,
                'expected_status' => $row['status'],
            ]),
        );
        if ($affected !== 1) {
            throw ImportExportException::stateConflict();
        }
        return $this->map(
            $this->byId($tenantId, $operationId, true) ?? throw ImportExportException::internal(),
            $tenantId,
        );
    }

    public function expireDue(int $limit = 100): int
    {
        $this->assertStorageMode();
        if ($limit < 1 || $limit > 1000) {
            throw ImportExportException::invalid();
        }
        $row = $this->one('SELECT * FROM pa_import_export_operation ORDER BY id LIMIT 1');
        if (is_array($row)) {
            $this->tenantScope->assertStorageRow($row);
        }
        return $this->connection->execute(sprintf("UPDATE pa_import_export_operation SET status = 'expired', result_file_key = NULL, error_file_key = NULL, revision = revision + 1, updated_at = UTC_TIMESTAMP(3) WHERE status IN ('succeeded','failed','cancelled') AND retention_until <= UTC_TIMESTAMP(3) ORDER BY id LIMIT %d", $limit));
    }

    /** @return array<string, mixed>|null */
    private function byIdempotency(int $tenantId, int $memberId, string $direction, string $providerKey, string $hash, bool $lock): ?array
    {
        $sql = 'SELECT * FROM pa_import_export_operation WHERE '
            . $this->tenantScope->where('created_by_member_id = :member_id AND direction = :direction AND provider_key = :provider_key AND idempotency_key_hash = :hash')
            . ($lock ? ' FOR UPDATE' : '');
        $row = $this->one($sql, $this->tenantScope->bindings($tenantId, [
            'member_id' => $memberId,
            'direction' => $direction,
            'provider_key' => $providerKey,
            'hash' => $hash,
        ]));
        if (is_array($row)) {
            $this->tenantScope->tenantId($row, $tenantId);
        }
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function byKey(int $tenantId, string $operationKey, bool $lock): ?array
    {
        $row = $this->one(
            'SELECT * FROM pa_import_export_operation WHERE ' . $this->tenantScope->where('operation_key = :operation_key')
            . ($lock ? ' FOR UPDATE' : ''),
            $this->tenantScope->bindings($tenantId, ['operation_key' => $operationKey]),
        );
        if (is_array($row)) {
            $this->tenantScope->tenantId($row, $tenantId);
        }
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function byId(int $tenantId, int $id, bool $lock): ?array
    {
        $row = $this->one(
            'SELECT * FROM pa_import_export_operation WHERE ' . $this->tenantScope->where('id = :id')
            . ($lock ? ' FOR UPDATE' : ''),
            $this->tenantScope->bindings($tenantId, ['id' => $id]),
        );
        if (is_array($row)) {
            $this->tenantScope->tenantId($row, $tenantId);
        }
        return is_array($row) ? $row : null;
    }

    /** @param array<string, mixed> $row */
    private function map(array $row, int $logicalTenantId): OperationRecord
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
            $this->tenantScope->tenantId($row, $logicalTenantId),
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

    /** @param array<string, string> $value */
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

    private function lastInsertId(): int
    {
        $id = $this->one('SELECT LAST_INSERT_ID() AS id')['id'] ?? null;
        if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id < 1) {
            throw ImportExportException::internal();
        }
        return (int) $id;
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array<string, mixed>|null
     */
    private function one(string $sql, array $parameters = []): ?array
    {
        $row = $this->connection->query($sql, $parameters)[0] ?? null;
        return is_array($row) ? $row : null;
    }

    private function isDuplicate(PDOException $exception): bool
    {
        $error = $exception->getData()['PDO Error Info'] ?? [];
        return (string) ($error['SQLSTATE'] ?? $exception->getCode()) === '23000'
            && (int) ($error['Driver Error Code'] ?? 0) === 1062;
    }

    private function assertStorageMode(): void
    {
        $this->tenantScope->assertStorageMode($this->connection->connect(), [
            'pa_import_export_operation',
            'pa_import_export_row_error',
        ]);
    }
}
