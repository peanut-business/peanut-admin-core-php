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
use Throwable;
use think\db\BaseQuery;
use think\facade\Db;

final class ImportExportStore
{
    private TenantColumnScope $tenantScope;

    public function __construct(
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
        try {
            $id = (int) Db::name('import_export_operation')->insertGetId($this->tenantData($tenantId, [
                'operation_key' => $operationKey, 'created_by_member_id' => $memberId,
                'provider_key' => $providerKey, 'direction' => $direction, 'input_file_key' => $inputFileKey,
                'schema_revision' => $schemaRevision, 'mapping_json' => $this->json($mapping),
                'idempotency_key_hash' => $idempotencyKeyHash, 'request_hash' => $requestHash,
                'retention_until' => Db::raw("TIMESTAMPADD(DAY, {$retentionDays}, UTC_TIMESTAMP(3))"),
                'created_at' => Db::raw('UTC_TIMESTAMP(3)'), 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]));
            $created = true;
        } catch (Throwable $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            $existing = $this->byIdempotency(
                $tenantId, $memberId, $direction, $providerKey, $idempotencyKeyHash, true,
            );
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
        $this->byKey($tenantId, $operationKey);
        $this->query('import_export_operation', $tenantId)->where('operation_key', $operationKey)
            ->where('status', 'queued')->where(function ($query) use ($jobKey): void {
                $query->whereNull('task_job_key')->whereOr('task_job_key', $jobKey);
            })->update([
                'task_job_key' => $jobKey, 'revision' => Db::raw('revision + 1'),
                'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]);
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
        $query = $this->query('import_export_operation', $tenantId)->where('status', $status);
        $total = (int) (clone $query)->count();

        return [
            'items' => array_values(array_map(
                fn(array $row): OperationRecord => $this->map($row, $tenantId),
                $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray(),
            )),
            'page' => $page, 'page_size' => $pageSize, 'total' => $total,
        ];
    }

    public function get(int $tenantId, string $operationKey): OperationRecord
    {
        $this->assertStorageMode();
        $row = $this->byKey($tenantId, $operationKey);
        if ($row === null) {
            throw ImportExportException::notFound();
        }

        return $this->map($row, $tenantId);
    }

    public function resultFile(int $tenantId, string $fileKey): OperationRecord
    {
        $this->assertStorageMode();
        $row = $this->query('import_export_operation', $tenantId)->where('result_file_key', $fileKey)
            ->where('status', 'succeeded')->where('retention_until', '>', Db::raw('UTC_TIMESTAMP(3)'))->find();
        if ($row === null) {
            throw ImportExportException::fileUnavailable();
        }

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
        if ($this->query('import_export_operation', $tenantId)->where('id', (int) $row['id'])
            ->where('revision', $revision)->update([
                'status' => $next, 'completed_at' => $next === 'cancelled' ? Db::raw('UTC_TIMESTAMP(3)') : null,
                'revision' => Db::raw('revision + 1'), 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]) !== 1) {
            throw ImportExportException::stateConflict();
        }

        return $this->map($this->byId($tenantId, (int) $row['id'], true) ?? throw ImportExportException::internal(), $tenantId);
    }

    public function beginAttempt(int $tenantId, string $operationKey, string $jobKey, int $attempt): OperationRecord
    {
        $this->assertStorageMode();
        $row = $this->byKey($tenantId, $operationKey, true);
        if ($row === null) {
            throw ImportExportException::notFound();
        }
        if (!is_string($row['task_job_key']) || !hash_equals($jobKey, $row['task_job_key'])
            || $attempt < 1 || $attempt > 10 || $attempt <= (int) $row['attempt_number']
            || !in_array($row['status'], ['queued', 'running'], true)) {
            throw ImportExportException::stateConflict();
        }
        if ($this->query('import_export_operation', $tenantId)->where('id', (int) $row['id'])
            ->where('attempt_number', '<', $attempt)->whereIn('status', ['queued', 'running'])->update([
                'status' => 'running', 'attempt_number' => $attempt, 'last_error_code' => null,
                'revision' => Db::raw('revision + 1'), 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]) !== 1) {
            throw ImportExportException::stateConflict();
        }

        return $this->map($this->byId($tenantId, (int) $row['id'], true) ?? throw ImportExportException::internal(), $tenantId);
    }

    public function checkpointProgressOrCancel(
        int $tenantId,
        int $operationId,
        string $jobKey,
        int $attempt,
        int $processed,
        int $accepted,
        int $rejected,
    ): OperationRecord {
        $this->assertStorageMode();
        if ($processed < 0 || $processed > 100000 || $accepted < 0 || $rejected < 0 || $accepted + $rejected > $processed) {
            throw ImportExportException::internal();
        }
        $row = $this->byId($tenantId, $operationId, true);
        if ($row === null || !is_string($row['task_job_key']) || !hash_equals($jobKey, $row['task_job_key'])
            || (int) $row['attempt_number'] !== $attempt || !in_array($row['status'], ['running', 'cancel_requested'], true)) {
            throw ImportExportException::stateConflict();
        }
        $cancelled = $row['status'] === 'cancel_requested';
        if (!$cancelled && (int) $row['processed_rows'] === $processed
            && (int) $row['accepted_rows'] === $accepted && (int) $row['rejected_rows'] === $rejected) {
            return $this->map($row, $tenantId);
        }
        $changes = [
            'status' => $cancelled ? 'cancelled' : 'running', 'processed_rows' => $processed,
            'accepted_rows' => $accepted, 'rejected_rows' => $rejected,
            'revision' => Db::raw('revision + 1'), 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
        ];
        if ($cancelled) {
            $changes += [
                'total_rows' => $processed, 'result_file_key' => null, 'error_file_key' => null,
                'last_error_code' => null, 'completed_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ];
        }
        if ($this->query('import_export_operation', $tenantId)->where('id', $operationId)
            ->where('task_job_key', $jobKey)->where('attempt_number', $attempt)->where('status', $row['status'])
            ->update($changes) !== 1) {
            throw ImportExportException::stateConflict();
        }

        return $this->map($this->byId($tenantId, $operationId, true) ?? throw ImportExportException::internal(), $tenantId);
    }

    public function addRowIssue(int $tenantId, int $operationId, int $rowNumber, RowIssue $issue): void
    {
        $this->assertStorageMode();
        $this->byId($tenantId, $operationId);
        try {
            Db::name('import_export_row_error')->insert($this->tenantData($tenantId, [
                'operation_id' => $operationId, 'row_number' => $rowNumber,
                'column_key' => $issue->columnKey, 'error_code' => $issue->code,
                'occurred_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]));
        } catch (Throwable $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
        }
    }

    /** @return list<array{row_number: int, column_key: string|null, error_code: string}> */
    public function rowIssues(int $tenantId, int $operationId, int $limit = 10000): array
    {
        $this->assertStorageMode();
        if ($limit < 1 || $limit > 10000) {
            throw ImportExportException::invalid();
        }
        $this->byId($tenantId, $operationId);
        $rows = $this->query('import_export_row_error', $tenantId)->where('operation_id', $operationId)
            ->order('row_number')->order('id')->limit($limit)->field('row_number,column_key,error_code')->select()->toArray();

        return array_values(array_map(static fn(array $row): array => [
            'row_number' => (int) $row['row_number'],
            'column_key' => is_string($row['column_key']) ? $row['column_key'] : null,
            'error_code' => (string) $row['error_code'],
        ], $rows));
    }

    public function finish(
        int $tenantId,
        int $operationId,
        string $jobKey,
        int $attempt,
        string $status,
        ?string $resultFileKey,
        ?string $errorFileKey,
        int $totalRows,
        ?string $errorCode = null,
    ): OperationRecord {
        $this->assertStorageMode();
        if (!in_array($status, ['succeeded', 'failed', 'cancelled'], true) || $totalRows < 0 || $totalRows > 100000
            || ($status === 'succeeded' && $errorCode !== null)
            || ($status === 'failed' && preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', (string) $errorCode) !== 1)) {
            throw ImportExportException::internal();
        }
        $row = $this->byId($tenantId, $operationId, true);
        if ($row === null || !is_string($row['task_job_key']) || !hash_equals($jobKey, $row['task_job_key'])
            || (int) $row['attempt_number'] !== $attempt || !in_array($row['status'], ['running', 'cancel_requested'], true)) {
            throw ImportExportException::stateConflict();
        }
        $cancelled = $row['status'] === 'cancel_requested';
        if ($this->query('import_export_operation', $tenantId)->where('id', $operationId)
            ->where('task_job_key', $jobKey)->where('attempt_number', $attempt)->where('status', $row['status'])
            ->update([
                'status' => $cancelled ? 'cancelled' : $status,
                'result_file_key' => $cancelled ? null : $resultFileKey,
                'error_file_key' => $cancelled ? null : $errorFileKey, 'total_rows' => $totalRows,
                'last_error_code' => $cancelled ? null : $errorCode,
                'completed_at' => Db::raw('UTC_TIMESTAMP(3)'), 'revision' => Db::raw('revision + 1'),
                'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]) !== 1) {
            throw ImportExportException::stateConflict();
        }

        return $this->map($this->byId($tenantId, $operationId, true) ?? throw ImportExportException::internal(), $tenantId);
    }

    public function expireDue(int $limit = 100): int
    {
        $this->assertStorageMode();
        if ($limit < 1 || $limit > 1000) {
            throw ImportExportException::invalid();
        }
        $sample = Db::name('import_export_operation')->order('id')->find();
        if ($sample !== null) {
            $this->tenantScope->assertStorageRow($sample);
        }

        return Db::name('import_export_operation')->whereIn('status', ['succeeded', 'failed', 'cancelled'])
            ->where('retention_until', '<=', Db::raw('UTC_TIMESTAMP(3)'))->order('id')->limit($limit)->update([
                'status' => 'expired', 'result_file_key' => null, 'error_file_key' => null,
                'revision' => Db::raw('revision + 1'), 'updated_at' => Db::raw('UTC_TIMESTAMP(3)'),
            ]);
    }

    /** @return array<string, mixed>|null */
    private function byIdempotency(
        int $tenantId,
        int $memberId,
        string $direction,
        string $providerKey,
        string $hash,
        bool $lock,
    ): ?array {
        $query = $this->query('import_export_operation', $tenantId)->where('created_by_member_id', $memberId)
            ->where('direction', $direction)->where('provider_key', $providerKey)->where('idempotency_key_hash', $hash);
        if ($lock) {
            $query->lock(true);
        }

        return $this->scopedRow($query->find(), $tenantId);
    }

    /** @return array<string, mixed>|null */
    private function byKey(int $tenantId, string $operationKey, bool $lock = false): ?array
    {
        $query = $this->query('import_export_operation', $tenantId)->where('operation_key', $operationKey);
        if ($lock) {
            $query->lock(true);
        }

        return $this->scopedRow($query->find(), $tenantId);
    }

    /** @return array<string, mixed>|null */
    private function byId(int $tenantId, int $id, bool $lock = false): ?array
    {
        $query = $this->query('import_export_operation', $tenantId)->where('id', $id);
        if ($lock) {
            $query->lock(true);
        }

        return $this->scopedRow($query->find(), $tenantId);
    }

    /** @param array<string, mixed>|null $row
     * @return array<string, mixed>|null
     */
    private function scopedRow(?array $row, int $tenantId): ?array
    {
        if ($row !== null) {
            $this->tenantScope->tenantId($row, $tenantId);
        }

        return $row;
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
            (int) $row['id'], (string) $row['operation_key'], $this->tenantScope->tenantId($row, $logicalTenantId),
            (int) $row['created_by_member_id'], (string) $row['provider_key'], (string) $row['direction'],
            (string) $row['status'], is_string($row['input_file_key']) ? $row['input_file_key'] : null,
            is_string($row['result_file_key']) ? $row['result_file_key'] : null,
            is_string($row['error_file_key']) ? $row['error_file_key'] : null,
            is_string($row['task_job_key']) ? $row['task_job_key'] : null, (string) $row['schema_revision'],
            $mapping, (int) $row['processed_rows'], (int) $row['accepted_rows'], (int) $row['rejected_rows'],
            (int) $row['total_rows'], (int) $row['attempt_number'], (int) $row['revision'],
            is_string($row['last_error_code']) ? $row['last_error_code'] : null,
            $this->time((string) $row['retention_until']), $this->time((string) $row['created_at']),
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

    private function query(string $table, int $tenantId): BaseQuery
    {
        $this->tenantScope->assertTenantId($tenantId);
        $query = Db::name($table);
        if ($this->tenantScope->usesTenantColumn()) {
            $query->where('tenant_id', $tenantId);
        }

        return $query;
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function tenantData(int $tenantId, array $data): array
    {
        $this->tenantScope->assertTenantId($tenantId);

        return $this->tenantScope->usesTenantColumn() ? ['tenant_id' => $tenantId, ...$data] : $data;
    }

    private function assertStorageMode(): void
    {
        $this->tenantScope->assertStorageMode(['pa_import_export_operation', 'pa_import_export_row_error']);
    }
}
