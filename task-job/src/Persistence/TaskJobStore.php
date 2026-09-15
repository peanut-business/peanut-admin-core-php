<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantColumnScope;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;
use PeanutAdmin\TaskJob\Application\JobRecord;
use PeanutAdmin\TaskJob\Application\TaskJobException;
use PeanutAdmin\TaskJob\Execution\JobClaim;
use PeanutAdmin\TaskJob\Persistence\Model\TaskJobAttemptRecord;
use PeanutAdmin\TaskJob\Persistence\Model\TaskJobEventRecord;
use PeanutAdmin\TaskJob\Persistence\Model\TaskJobRecord;
use Throwable;
use think\db\BaseQuery;
use think\db\Raw;

final class TaskJobStore
{
    private TenantColumnScope $tenantScope;

    public function __construct(
        TenantPersistenceMode $mode = TenantPersistenceMode::TenantScoped,
        ?int $instanceTenantId = null,
    ) {
        $this->tenantScope = new TenantColumnScope($mode, $instanceTenantId);
        $this->tenantScope->assertRuntimeConfigured();
    }

    public function enqueue(
        int $tenantId,
        int $memberId,
        string $jobKey,
        string $taskType,
        string $handlerKey,
        string $payloadJson,
        string $trustedEnvelope,
        ?string $idempotencyKeyHash,
        string $requestHash,
        int $maxAttempts,
        int $initialDelaySeconds,
    ): JobRecord {
        $this->assertStorageMode();
        $created = false;
        $data = $this->tenantData($tenantId, [
            'job_key' => $jobKey, 'task_type' => $taskType, 'handler_key' => $handlerKey,
            'payload_json' => $payloadJson, 'payload_hash' => hash('sha256', $payloadJson),
            'trusted_envelope' => $trustedEnvelope, 'idempotency_key_hash' => $idempotencyKeyHash,
            'request_hash' => $requestHash, 'status' => 'queued', 'max_attempts' => $maxAttempts,
            'available_at' => new Raw("TIMESTAMPADD(SECOND, {$initialDelaySeconds}, UTC_TIMESTAMP(3))"),
            'created_by_member_id' => $memberId,
            'created_at' => new Raw('UTC_TIMESTAMP(3)'), 'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
        try {
            $id = (int) TaskJobRecord::insertGetId($data);
            $created = true;
        } catch (Throwable $exception) {
            if ($idempotencyKeyHash === null || (string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            $existing = $this->idempotentRow($tenantId, $memberId, $taskType, $idempotencyKeyHash, true);
            if ($existing === null || !hash_equals((string) $existing['request_hash'], $requestHash)) {
                throw TaskJobException::conflict();
            }
            $id = (int) $existing['id'];
        }
        $row = $this->rowById($tenantId, $id, true);
        if ($row === null || !hash_equals((string) $row['request_hash'], $requestHash)) {
            throw TaskJobException::conflict();
        }
        if ($created) {
            $this->insertEvent($tenantId, $id, 'tenant.task.submitted', $memberId, [
                'task_type' => $taskType,
                'producer_resource' => $this->envelopeField($trustedEnvelope, 'resource_key'),
                'producer_operation' => $this->envelopeField($trustedEnvelope, 'operation'),
                'max_attempts' => $maxAttempts,
            ]);
        }

        return $this->map($row, $tenantId);
    }

    /** @return array{items: list<JobRecord>, page: int, page_size: int, total: int} */
    public function list(int $tenantId, string $status, int $page, int $pageSize): array
    {
        $this->assertStorageMode();
        if ($page > 1_000_000) {
            throw TaskJobException::invalid();
        }
        $query = $this->query('task_job', $tenantId)->where('status', $status);
        $total = (int) (clone $query)->count();
        $rows = $query->order('id', 'desc')->page($page, $pageSize)->select()->toArray();

        return [
            'items' => array_values(array_map(fn(array $row): JobRecord => $this->map($row, $tenantId), $rows)),
            'page' => $page, 'page_size' => $pageSize, 'total' => $total,
        ];
    }

    public function get(int $tenantId, string $jobKey): JobRecord
    {
        $this->assertStorageMode();
        $row = $this->rowByJobKey($tenantId, $jobKey);
        if ($row === null) {
            throw TaskJobException::notFound();
        }

        return $this->map($row, $tenantId);
    }

    public function cancel(int $tenantId, int $actorMemberId, string $jobKey, int $revision): JobRecord
    {
        $this->assertStorageMode();
        $row = $this->rowByJobKey($tenantId, $jobKey, true);
        if ($row === null) {
            throw TaskJobException::notFound();
        }
        if ($this->query('task_job', $tenantId)->where('job_key', $jobKey)->where('status', 'queued')
            ->where('revision', $revision)->update([
                'status' => 'cancelled', 'completed_at' => new Raw('UTC_TIMESTAMP(3)'),
                'revision' => new Raw('revision + 1'), 'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
            ]) !== 1) {
            throw TaskJobException::stateConflict();
        }
        $this->insertEvent($tenantId, (int) $row['id'], 'tenant.task.cancelled', $actorMemberId, ['revision' => $revision + 1]);

        return $this->map($this->rowById($tenantId, (int) $row['id']) ?? throw TaskJobException::internal(), $tenantId);
    }

    public function retryDead(int $tenantId, int $actorMemberId, string $jobKey, int $revision): JobRecord
    {
        $this->assertStorageMode();
        $row = $this->rowByJobKey($tenantId, $jobKey, true);
        if ($row === null) {
            throw TaskJobException::notFound();
        }
        if ($this->query('task_job', $tenantId)->where('job_key', $jobKey)->where('status', 'dead')
            ->where('revision', $revision)->where('attempt_count', '<', 10)->update([
                'status' => 'queued', 'max_attempts' => new Raw('attempt_count + 1'),
                'available_at' => new Raw('UTC_TIMESTAMP(3)'), 'last_error_code' => null,
                'completed_at' => null, 'revision' => new Raw('revision + 1'),
                'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
            ]) !== 1) {
            throw TaskJobException::stateConflict();
        }
        $this->insertEvent($tenantId, (int) $row['id'], 'tenant.task.retried', $actorMemberId, ['revision' => $revision + 1]);

        return $this->map($this->rowById($tenantId, (int) $row['id']) ?? throw TaskJobException::internal(), $tenantId);
    }

    public function claim(int $tenantId, string $workerId, int $leaseSeconds): ?JobClaim
    {
        $this->assertStorageMode();
        $this->recoverExpired($tenantId);
        $row = $this->query('task_job', $tenantId)->where('status', 'queued')
            ->where('available_at', '<=', new Raw('UTC_TIMESTAMP(3)'))->order('priority', 'desc')->order('id')
            ->lock('FOR UPDATE SKIP LOCKED')->find()?->toArray();
        if ($row === null) {
            return null;
        }
        $this->tenantScope->tenantId($row, $tenantId);
        $payload = $this->payload((string) $row['payload_json']);
        $this->assertPayloadHash($row, $payload);
        $leaseToken = bin2hex(random_bytes(32));
        $leaseHash = hash('sha256', $leaseToken);
        $workerHash = hash('sha256', $workerId);
        $attempt = (int) $row['attempt_count'] + 1;
        if ($this->query('task_job', $tenantId)->where('id', (int) $row['id'])->where('status', 'queued')->update([
            'status' => 'running', 'attempt_count' => $attempt, 'lease_owner_hash' => $workerHash,
            'lease_token_hash' => $leaseHash,
            'lease_expires_at' => new Raw("TIMESTAMPADD(SECOND, {$leaseSeconds}, UTC_TIMESTAMP(3))"),
            'revision' => new Raw('revision + 1'), 'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]) !== 1) {
            throw TaskJobException::stateConflict();
        }
        TaskJobAttemptRecord::insert($this->tenantData($tenantId, [
            'job_id' => $row['id'], 'attempt_number' => $attempt, 'worker_id_hash' => $workerHash,
            'lease_token_hash' => $leaseHash, 'status' => 'running', 'started_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]));
        $this->insertEvent($tenantId, (int) $row['id'], 'tenant.task.claimed', null, ['attempt' => $attempt]);

        return new JobClaim(
            (int) $row['id'], (string) $row['job_key'], $tenantId, (string) $row['handler_key'],
            $payload, (string) $row['trusted_envelope'], $attempt, (int) $row['max_attempts'], $leaseToken,
        );
    }

    public function renew(JobClaim $claim, int $leaseSeconds): void
    {
        $this->assertStorageMode();
        if ($this->query('task_job', $claim->tenantId)->where('id', $claim->id)->where('status', 'running')
            ->where('lease_token_hash', hash('sha256', $claim->leaseToken))
            ->where('lease_expires_at', '>', new Raw('UTC_TIMESTAMP(3)'))->update([
                'lease_expires_at' => new Raw("TIMESTAMPADD(SECOND, {$leaseSeconds}, UTC_TIMESTAMP(3))"),
                'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
            ]) !== 1) {
            throw TaskJobException::stateConflict();
        }
    }

    public function assertExecutable(JobClaim $claim): void
    {
        $this->assertStorageMode();
        $job = $this->rowById($claim->tenantId, $claim->id);
        $attempt = $this->query('task_job_attempt', $claim->tenantId)->where('job_id', $claim->id)
            ->where('attempt_number', $claim->attemptNumber)->where('status', 'running')->find()?->toArray();
        $leaseHash = hash('sha256', $claim->leaseToken);
        if ($job === null || $attempt === null || $job['status'] !== 'running'
            || (int) $job['attempt_count'] !== $claim->attemptNumber
            || !is_string($job['lease_expires_at']) || $job['lease_expires_at'] <= $this->now()
            || !is_string($job['lease_token_hash']) || !is_string($attempt['lease_token_hash'])
            || !hash_equals($claim->jobKey, (string) $job['job_key'])
            || !hash_equals($claim->handlerKey, (string) $job['handler_key'])
            || !hash_equals($leaseHash, $job['lease_token_hash'])
            || !hash_equals($leaseHash, $attempt['lease_token_hash'])) {
            throw TaskJobException::stateConflict();
        }
        $payload = $this->payload((string) $job['payload_json']);
        $this->assertPayloadHash($job, $payload);
        if (!hash_equals($this->payloadHash($claim->payload), $this->payloadHash($payload))) {
            throw TaskJobException::internal();
        }
    }

    public function succeed(JobClaim $claim): void
    {
        $this->assertStorageMode();
        $this->finish($claim, 'succeeded', null, 0);
    }

    public function fail(JobClaim $claim, string $errorCode, bool $retryable, int $backoffSeconds): string
    {
        $this->assertStorageMode();
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $errorCode) !== 1 || $backoffSeconds < 0 || $backoffSeconds > 300) {
            throw TaskJobException::invalid();
        }

        return $this->finish($claim, $retryable ? 'retry' : 'dead', $errorCode, $backoffSeconds);
    }

    private function finish(JobClaim $claim, string $outcome, ?string $errorCode, int $backoffSeconds): string
    {
        $row = $this->rowById($claim->tenantId, $claim->id, true);
        $leaseHash = hash('sha256', $claim->leaseToken);
        if ($row === null || $row['status'] !== 'running' || !is_string($row['lease_token_hash'])
            || !hash_equals($row['lease_token_hash'], $leaseHash)
            || (int) $row['attempt_count'] !== $claim->attemptNumber) {
            throw TaskJobException::stateConflict();
        }
        $canRetry = $outcome === 'retry' && $claim->attemptNumber < (int) $row['max_attempts'];
        $jobStatus = $outcome === 'succeeded' ? 'succeeded' : ($canRetry ? 'queued' : 'dead');
        $attemptStatus = $outcome === 'succeeded' ? 'succeeded' : ($canRetry ? 'retry' : 'dead');
        if ($this->query('task_job_attempt', $claim->tenantId)->where('job_id', $claim->id)
            ->where('attempt_number', $claim->attemptNumber)->where('status', 'running')
            ->where('lease_token_hash', $leaseHash)->update([
                'status' => $attemptStatus, 'error_code' => $errorCode,
                'completed_at' => new Raw('UTC_TIMESTAMP(3)'),
            ]) !== 1) {
            throw TaskJobException::stateConflict();
        }
        if ($this->query('task_job', $claim->tenantId)->where('id', $claim->id)->where('status', 'running')
            ->where('lease_token_hash', $leaseHash)->where('lease_expires_at', '>', new Raw('UTC_TIMESTAMP(3)'))->update([
                'status' => $jobStatus,
                'available_at' => new Raw('TIMESTAMPADD(SECOND, ' . ($canRetry ? $backoffSeconds : 0) . ', UTC_TIMESTAMP(3))'),
                'lease_owner_hash' => null, 'lease_token_hash' => null, 'lease_expires_at' => null,
                'last_error_code' => $errorCode, 'completed_at' => $jobStatus === 'queued' ? null : $this->now(),
                'revision' => new Raw('revision + 1'), 'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
            ]) !== 1) {
            throw TaskJobException::stateConflict();
        }
        $event = $jobStatus === 'queued' ? 'tenant.task.retry_scheduled' : 'tenant.task.' . $jobStatus;
        $metadata = ['attempt' => $claim->attemptNumber];
        if ($errorCode !== null) {
            $metadata['error_code'] = $errorCode;
        }
        if ($canRetry) {
            $metadata['backoff_seconds'] = $backoffSeconds;
        }
        $this->insertEvent($claim->tenantId, $claim->id, $event, null, $metadata);

        return $jobStatus;
    }

    private function recoverExpired(int $tenantId): void
    {
        $rows = $this->query('task_job', $tenantId)->where('status', 'running')
            ->where('lease_expires_at', '<=', new Raw('UTC_TIMESTAMP(3)'))->order('id')->lock(true)->select()->toArray();
        foreach ($rows as $row) {
            $attempt = $this->query('task_job_attempt', $tenantId)->where('job_id', (int) $row['id'])
                ->where('attempt_number', (int) $row['attempt_count'])->where('status', 'running')->lock(true)->find()?->toArray();
            if ($attempt === null || !is_string($row['lease_token_hash']) || !is_string($attempt['lease_token_hash'])
                || !hash_equals($row['lease_token_hash'], $attempt['lease_token_hash'])) {
                throw TaskJobException::internal();
            }
            $dead = (int) $row['attempt_count'] >= (int) $row['max_attempts'];
            if ($this->query('task_job_attempt', $tenantId)->where('job_id', (int) $row['id'])
                ->where('attempt_number', (int) $row['attempt_count'])->where('status', 'running')
                ->where('lease_token_hash', $row['lease_token_hash'])->update([
                    'status' => 'abandoned', 'error_code' => 'TASK_LEASE_EXPIRED',
                    'completed_at' => new Raw('UTC_TIMESTAMP(3)'),
                ]) !== 1) {
                throw TaskJobException::internal();
            }
            if ($this->query('task_job', $tenantId)->where('id', (int) $row['id'])->where('status', 'running')
                ->where('lease_token_hash', $row['lease_token_hash'])
                ->where('lease_expires_at', '<=', new Raw('UTC_TIMESTAMP(3)'))->update([
                    'status' => $dead ? 'dead' : 'queued', 'available_at' => new Raw('UTC_TIMESTAMP(3)'),
                    'lease_owner_hash' => null, 'lease_token_hash' => null, 'lease_expires_at' => null,
                    'last_error_code' => 'TASK_LEASE_EXPIRED', 'completed_at' => $dead ? $this->now() : null,
                    'revision' => new Raw('revision + 1'), 'updated_at' => new Raw('UTC_TIMESTAMP(3)'),
                ]) !== 1) {
                throw TaskJobException::stateConflict();
            }
            $this->insertEvent($tenantId, (int) $row['id'], $dead ? 'tenant.task.dead' : 'tenant.task.lease_recovered', null, [
                'attempt' => (int) $row['attempt_count'], 'error_code' => 'TASK_LEASE_EXPIRED',
            ]);
        }
    }

    /** @return array<string, mixed>|null */
    private function rowById(int $tenantId, int $id, bool $lock = false): ?array
    {
        $query = $this->query('task_job', $tenantId)->where('id', $id);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find()?->toArray();
        if ($row !== null) {
            $this->tenantScope->tenantId($row, $tenantId);
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    private function rowByJobKey(int $tenantId, string $jobKey, bool $lock = false): ?array
    {
        $query = $this->query('task_job', $tenantId)->where('job_key', $jobKey);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find()?->toArray();
        if ($row !== null) {
            $this->tenantScope->tenantId($row, $tenantId);
        }

        return $row;
    }

    /** @param array<string, bool|int|string|null> $metadata */
    private function insertEvent(int $tenantId, int $jobId, string $event, ?int $memberId, array $metadata): void
    {
        try {
            $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw TaskJobException::internal();
        }
        TaskJobEventRecord::insert($this->tenantData($tenantId, [
            'job_id' => $jobId, 'event_key' => $event, 'actor_member_id' => $memberId,
            'metadata_json' => $json, 'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]));
    }

    private function envelopeField(string $encoded, string $field): string
    {
        try {
            $document = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw TaskJobException::internal();
        }
        $value = is_array($document) && is_array($document['payload'] ?? null)
            ? ($document['payload'][$field] ?? null)
            : null;
        if (!is_string($value) || $value === '') {
            throw TaskJobException::internal();
        }

        return $value;
    }

    /** @return array<string, mixed>|null */
    private function idempotentRow(
        int $tenantId,
        int $memberId,
        string $taskType,
        string $keyHash,
        bool $lock,
    ): ?array {
        $query = $this->query('task_job', $tenantId)->where('created_by_member_id', $memberId)
            ->where('task_type', $taskType)->where('idempotency_key_hash', $keyHash);
        if ($lock) {
            $query->lock(true);
        }

        return $query->find()?->toArray();
    }

    /** @param array<string, mixed> $row */
    private function map(array $row, int $logicalTenantId): JobRecord
    {
        return new JobRecord(
            (int) $row['id'], (string) $row['job_key'], $this->tenantScope->tenantId($row, $logicalTenantId),
            (string) $row['task_type'], (string) $row['status'], (int) $row['attempt_count'],
            (int) $row['max_attempts'], (int) $row['revision'],
            is_string($row['last_error_code']) ? $row['last_error_code'] : null,
            $this->timestamp($row['available_at']), $this->timestamp($row['created_at']),
            $this->timestamp($row['updated_at']),
            $row['completed_at'] === null ? null : $this->timestamp($row['completed_at']),
        );
    }

    /** @return array<string, mixed> */
    private function payload(string $json): array
    {
        try {
            $value = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw TaskJobException::internal();
        }
        if (!is_array($value) || array_is_list($value)) {
            throw TaskJobException::internal();
        }

        return $value;
    }

    /** @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     */
    private function assertPayloadHash(array $row, array $payload): void
    {
        $stored = $row['payload_hash'] ?? null;
        if (!is_string($stored) || preg_match('/^[0-9a-f]{64}$/D', $stored) !== 1
            || !hash_equals($stored, $this->payloadHash($payload))) {
            throw TaskJobException::internal();
        }
    }

    /** @param array<string, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        try {
            return hash('sha256', json_encode(
                $this->normalizePayload($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException) {
            throw TaskJobException::internal();
        }
    }

    private function normalizePayload(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizePayload($item);
        }

        return $value;
    }

    private function query(string $table, int $tenantId): BaseQuery
    {
        $this->tenantScope->assertTenantId($tenantId);
        $query = match ($table) {
            'task_job' => TaskJobRecord::where([]),
            'task_job_attempt' => TaskJobAttemptRecord::where([]),
            'task_job_event' => TaskJobEventRecord::where([]),
            default => throw TaskJobException::internal(),
        };
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
        $this->tenantScope->assertStorageMode(['pa_task_job', 'pa_task_job_attempt', 'pa_task_job_event']);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.v');
    }

    private function timestamp(mixed $value): string
    {
        if (!is_string($value)) {
            throw TaskJobException::internal();
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value, new DateTimeZone('UTC'));
        if (!$date instanceof DateTimeImmutable || $date->format('Y-m-d H:i:s.v') !== $value) {
            throw TaskJobException::internal();
        }

        return $date->format('Y-m-d\TH:i:s.v\Z');
    }
}
