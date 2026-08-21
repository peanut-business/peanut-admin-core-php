<?php

declare(strict_types=1);

namespace PeanutAdmin\TaskJob\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PDO;
use PDOException;
use PeanutAdmin\TaskJob\Application\JobRecord;
use PeanutAdmin\TaskJob\Application\TaskJobException;
use PeanutAdmin\TaskJob\Execution\JobClaim;
use Throwable;

final readonly class PdoTaskJobRepository
{
    public function __construct(private PDO $pdo) {}

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
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->begin();
        }
        try {
            $created = false;
            $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_task_job (
  job_key, tenant_id, task_type, handler_key, payload_json, payload_hash,
  trusted_envelope, idempotency_key_hash, request_hash, status, max_attempts,
  available_at, created_by_member_id, created_at, updated_at
) VALUES (
  :job_key, :tenant_id, :task_type, :handler_key, :payload_json, :payload_hash,
  :trusted_envelope, :idempotency_key_hash, :request_hash, 'queued', :max_attempts,
  TIMESTAMPADD(SECOND, :initial_delay, UTC_TIMESTAMP(3)), :member_id, UTC_TIMESTAMP(3), UTC_TIMESTAMP(3)
)
SQL);
            try {
                $statement->execute([
                    'job_key' => $jobKey,
                    'tenant_id' => $tenantId,
                    'task_type' => $taskType,
                    'handler_key' => $handlerKey,
                    'payload_json' => $payloadJson,
                    'payload_hash' => hash('sha256', $payloadJson),
                    'trusted_envelope' => $trustedEnvelope,
                    'idempotency_key_hash' => $idempotencyKeyHash,
                    'request_hash' => $requestHash,
                    'max_attempts' => $maxAttempts,
                    'initial_delay' => $initialDelaySeconds,
                    'member_id' => $memberId,
                ]);
                $id = (int) $this->pdo->lastInsertId();
                $created = true;
            } catch (PDOException $exception) {
                if ($idempotencyKeyHash === null || $exception->getCode() !== '23000') {
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
            if ($ownsTransaction) {
                $this->pdo->commit();
            }

            return $this->map($row);
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $this->rollback();
            }
            throw $exception;
        }
    }

    /** @return array{items: list<JobRecord>, page: int, page_size: int, total: int} */
    public function list(int $tenantId, string $status, int $page, int $pageSize): array
    {
        if ($page > 1_000_000) {
            throw TaskJobException::invalid();
        }
        $offset = ($page - 1) * $pageSize;
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM pa_task_job WHERE tenant_id = :tenant_id AND status = :status');
        $count->execute(['tenant_id' => $tenantId, 'status' => $status]);
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT * FROM pa_task_job
WHERE tenant_id = :tenant_id AND status = :status
ORDER BY id DESC LIMIT :limit OFFSET :offset
SQL);
        $statement->bindValue('tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue('status', $status);
        $statement->bindValue('limit', $pageSize, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        return [
            'items' => array_map($this->map(...), $statement->fetchAll(PDO::FETCH_ASSOC)),
            'page' => $page,
            'page_size' => $pageSize,
            'total' => (int) $count->fetchColumn(),
        ];
    }

    public function get(int $tenantId, string $jobKey): JobRecord
    {
        $statement = $this->pdo->prepare('SELECT * FROM pa_task_job WHERE tenant_id = :tenant_id AND job_key = :job_key');
        $statement->execute(['tenant_id' => $tenantId, 'job_key' => $jobKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw TaskJobException::notFound();
        }

        return $this->map($row);
    }

    public function cancel(int $tenantId, int $actorMemberId, string $jobKey, int $revision): JobRecord
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->begin();
        }
        try {
            $row = $this->rowByJobKey($tenantId, $jobKey, true);
            if ($row === null) {
                throw TaskJobException::notFound();
            }
            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_task_job
SET status = 'cancelled', completed_at = UTC_TIMESTAMP(3), revision = revision + 1, updated_at = UTC_TIMESTAMP(3)
WHERE tenant_id = :tenant_id AND job_key = :job_key AND status = 'queued' AND revision = :revision
SQL);
            $statement->execute(['tenant_id' => $tenantId, 'job_key' => $jobKey, 'revision' => $revision]);
            if ($statement->rowCount() !== 1) {
                throw TaskJobException::stateConflict();
            }
            $this->insertEvent($tenantId, (int) $row['id'], 'tenant.task.cancelled', $actorMemberId, ['revision' => $revision + 1]);
            $updated = $this->rowById($tenantId, (int) $row['id'], false);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $this->map($updated ?? throw TaskJobException::internal());
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $this->rollback();
            }
            throw $exception;
        }
    }

    public function retryDead(int $tenantId, int $actorMemberId, string $jobKey, int $revision): JobRecord
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->begin();
        }
        try {
            $row = $this->rowByJobKey($tenantId, $jobKey, true);
            if ($row === null) {
                throw TaskJobException::notFound();
            }
            $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_task_job
SET status = 'queued', max_attempts = attempt_count + 1, available_at = UTC_TIMESTAMP(3),
    last_error_code = NULL, completed_at = NULL, revision = revision + 1, updated_at = UTC_TIMESTAMP(3)
WHERE tenant_id = :tenant_id AND job_key = :job_key AND status = 'dead' AND revision = :revision
  AND attempt_count < 10
SQL);
            $statement->execute(['tenant_id' => $tenantId, 'job_key' => $jobKey, 'revision' => $revision]);
            if ($statement->rowCount() !== 1) {
                throw TaskJobException::stateConflict();
            }
            $this->insertEvent($tenantId, (int) $row['id'], 'tenant.task.retried', $actorMemberId, ['revision' => $revision + 1]);
            $updated = $this->rowById($tenantId, (int) $row['id'], false);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $this->map($updated ?? throw TaskJobException::internal());
        } catch (Throwable $exception) {
            if ($ownsTransaction) {
                $this->rollback();
            }
            throw $exception;
        }
    }

    public function claim(int $tenantId, string $workerId, int $leaseSeconds): ?JobClaim
    {
        $this->begin();
        try {
            $this->recoverExpired($tenantId);
            $statement = $this->pdo->prepare(<<<'SQL'
SELECT * FROM pa_task_job
WHERE tenant_id = :tenant_id AND status = 'queued' AND available_at <= UTC_TIMESTAMP(3)
ORDER BY priority DESC, id ASC LIMIT 1 FOR UPDATE SKIP LOCKED
SQL);
            $statement->execute(['tenant_id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $this->pdo->commit();
                return null;
            }
            $payload = $this->payload((string) $row['payload_json']);
            $this->assertPayloadHash($row, $payload);
            $leaseToken = bin2hex(random_bytes(32));
            $leaseHash = hash('sha256', $leaseToken);
            $workerHash = hash('sha256', $workerId);
            $attempt = (int) $row['attempt_count'] + 1;
            $update = $this->pdo->prepare(<<<'SQL'
UPDATE pa_task_job
SET status = 'running', attempt_count = :attempt, lease_owner_hash = :worker_hash,
    lease_token_hash = :lease_hash, lease_expires_at = TIMESTAMPADD(SECOND, :lease_seconds, UTC_TIMESTAMP(3)),
    revision = revision + 1, updated_at = UTC_TIMESTAMP(3)
WHERE id = :id AND tenant_id = :tenant_id AND status = 'queued'
SQL);
            $update->execute([
                'attempt' => $attempt,
                'worker_hash' => $workerHash,
                'lease_hash' => $leaseHash,
                'lease_seconds' => $leaseSeconds,
                'id' => $row['id'],
                'tenant_id' => $tenantId,
            ]);
            if ($update->rowCount() !== 1) {
                throw TaskJobException::stateConflict();
            }
            $insert = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_task_job_attempt (
  tenant_id, job_id, attempt_number, worker_id_hash, lease_token_hash, status, started_at
) VALUES (:tenant_id, :job_id, :attempt, :worker_hash, :lease_hash, 'running', UTC_TIMESTAMP(3))
SQL);
            $insert->execute([
                'tenant_id' => $tenantId,
                'job_id' => $row['id'],
                'attempt' => $attempt,
                'worker_hash' => $workerHash,
                'lease_hash' => $leaseHash,
            ]);
            $this->insertEvent($tenantId, (int) $row['id'], 'tenant.task.claimed', null, ['attempt' => $attempt]);
            $this->pdo->commit();

            return new JobClaim(
                (int) $row['id'],
                (string) $row['job_key'],
                $tenantId,
                (string) $row['handler_key'],
                $payload,
                (string) $row['trusted_envelope'],
                $attempt,
                (int) $row['max_attempts'],
                $leaseToken,
            );
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    public function renew(JobClaim $claim, int $leaseSeconds): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
UPDATE pa_task_job
SET lease_expires_at = TIMESTAMPADD(SECOND, :lease_seconds, UTC_TIMESTAMP(3)), updated_at = UTC_TIMESTAMP(3)
WHERE id = :id AND tenant_id = :tenant_id AND status = 'running'
  AND lease_token_hash = :lease_hash AND lease_expires_at > UTC_TIMESTAMP(3)
SQL);
        $statement->execute([
            'lease_seconds' => $leaseSeconds,
            'id' => $claim->id,
            'tenant_id' => $claim->tenantId,
            'lease_hash' => hash('sha256', $claim->leaseToken),
        ]);
        if ($statement->rowCount() !== 1) {
            throw TaskJobException::stateConflict();
        }
    }

    public function assertExecutable(JobClaim $claim): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT job.job_key, job.handler_key, job.attempt_count, job.payload_json, job.payload_hash,
       job.lease_token_hash AS job_lease_token_hash,
       attempt.lease_token_hash AS attempt_lease_token_hash,
       job.lease_expires_at > UTC_TIMESTAMP(3) AS lease_valid
FROM pa_task_job job
JOIN pa_task_job_attempt attempt
  ON attempt.tenant_id = job.tenant_id
 AND attempt.job_id = job.id
 AND attempt.attempt_number = job.attempt_count
 AND attempt.status = 'running'
WHERE job.id = :id AND job.tenant_id = :tenant_id AND job.status = 'running'
  AND job.attempt_count = :attempt
SQL);
        $statement->execute(['id' => $claim->id, 'tenant_id' => $claim->tenantId, 'attempt' => $claim->attemptNumber]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $leaseHash = hash('sha256', $claim->leaseToken);
        if (!is_array($row)
            || (int) $row['lease_valid'] !== 1
            || !is_string($row['job_lease_token_hash'])
            || !is_string($row['attempt_lease_token_hash'])
            || !is_string($row['job_key'])
            || !is_string($row['handler_key'])
            || !hash_equals($claim->jobKey, $row['job_key'])
            || !hash_equals($claim->handlerKey, $row['handler_key'])
            || !hash_equals($leaseHash, $row['job_lease_token_hash'])
            || !hash_equals($leaseHash, $row['attempt_lease_token_hash'])
        ) {
            throw TaskJobException::stateConflict();
        }
        $payload = $this->payload((string) $row['payload_json']);
        $this->assertPayloadHash($row, $payload);
        if (!hash_equals($this->payloadHash($claim->payload), $this->payloadHash($payload))) {
            throw TaskJobException::internal();
        }
    }

    public function succeed(JobClaim $claim): void
    {
        $this->finish($claim, 'succeeded', null, 0);
    }

    public function fail(JobClaim $claim, string $errorCode, bool $retryable, int $backoffSeconds): string
    {
        if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/D', $errorCode) !== 1 || $backoffSeconds < 0 || $backoffSeconds > 300) {
            throw TaskJobException::invalid();
        }
        return $this->finish($claim, $retryable ? 'retry' : 'dead', $errorCode, $backoffSeconds);
    }

    private function finish(JobClaim $claim, string $outcome, ?string $errorCode, int $backoffSeconds): string
    {
        $this->begin();
        try {
            $row = $this->rowById($claim->tenantId, $claim->id, true);
            $leaseHash = hash('sha256', $claim->leaseToken);
            if ($row === null
                || $row['status'] !== 'running'
                || !is_string($row['lease_token_hash'])
                || !hash_equals($row['lease_token_hash'], $leaseHash)
                || (int) $row['attempt_count'] !== $claim->attemptNumber
            ) {
                throw TaskJobException::stateConflict();
            }
            $canRetry = $outcome === 'retry' && $claim->attemptNumber < (int) $row['max_attempts'];
            $jobStatus = $outcome === 'succeeded' ? 'succeeded' : ($canRetry ? 'queued' : 'dead');
            $attemptStatus = $outcome === 'succeeded' ? 'succeeded' : ($canRetry ? 'retry' : 'dead');
            $attempt = $this->pdo->prepare(<<<'SQL'
UPDATE pa_task_job_attempt
SET status = :status, error_code = :error_code, completed_at = UTC_TIMESTAMP(3)
WHERE tenant_id = :tenant_id AND job_id = :job_id AND attempt_number = :attempt
  AND status = 'running' AND lease_token_hash = :lease_hash
SQL);
            $attempt->execute([
                'status' => $attemptStatus,
                'error_code' => $errorCode,
                'tenant_id' => $claim->tenantId,
                'job_id' => $claim->id,
                'attempt' => $claim->attemptNumber,
                'lease_hash' => $leaseHash,
            ]);
            if ($attempt->rowCount() !== 1) {
                throw TaskJobException::stateConflict();
            }
            $job = $this->pdo->prepare(<<<'SQL'
UPDATE pa_task_job
SET status = :status, available_at = TIMESTAMPADD(SECOND, :backoff, UTC_TIMESTAMP(3)),
    lease_owner_hash = NULL, lease_token_hash = NULL, lease_expires_at = NULL,
    last_error_code = :error_code, completed_at = :completed_at,
    revision = revision + 1, updated_at = UTC_TIMESTAMP(3)
WHERE id = :id AND tenant_id = :tenant_id AND status = 'running'
  AND lease_token_hash = :lease_hash AND lease_expires_at > UTC_TIMESTAMP(3)
SQL);
            $job->execute([
                'status' => $jobStatus,
                'backoff' => $canRetry ? $backoffSeconds : 0,
                'error_code' => $errorCode,
                'completed_at' => $jobStatus === 'queued' ? null : $this->now(),
                'id' => $claim->id,
                'tenant_id' => $claim->tenantId,
                'lease_hash' => $leaseHash,
            ]);
            if ($job->rowCount() !== 1) {
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
            $this->pdo->commit();
            return $jobStatus;
        } catch (Throwable $exception) {
            $this->rollback();
            throw $exception;
        }
    }

    private function recoverExpired(int $tenantId): void
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT job.id, job.attempt_count, job.max_attempts,
       job.lease_token_hash AS job_lease_token_hash,
       attempt.lease_token_hash AS attempt_lease_token_hash
FROM pa_task_job job
LEFT JOIN pa_task_job_attempt attempt
  ON attempt.tenant_id = job.tenant_id
 AND attempt.job_id = job.id
 AND attempt.attempt_number = job.attempt_count
 AND attempt.status = 'running'
WHERE job.tenant_id = :tenant_id AND job.status = 'running'
  AND job.lease_expires_at <= UTC_TIMESTAMP(3)
ORDER BY job.id ASC FOR UPDATE
SQL);
        $statement->execute(['tenant_id' => $tenantId]);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_string($row['job_lease_token_hash'])
                || !is_string($row['attempt_lease_token_hash'])
                || !hash_equals($row['job_lease_token_hash'], $row['attempt_lease_token_hash'])
            ) {
                throw TaskJobException::internal();
            }
            $dead = (int) $row['attempt_count'] >= (int) $row['max_attempts'];
            $attempt = $this->pdo->prepare(<<<'SQL'
UPDATE pa_task_job_attempt
SET status = 'abandoned', error_code = 'TASK_LEASE_EXPIRED', completed_at = UTC_TIMESTAMP(3)
WHERE tenant_id = :tenant_id AND job_id = :job_id AND attempt_number = :attempt
  AND status = 'running' AND lease_token_hash = :lease_hash
SQL);
            $attempt->execute([
                'tenant_id' => $tenantId,
                'job_id' => $row['id'],
                'attempt' => $row['attempt_count'],
                'lease_hash' => $row['job_lease_token_hash'],
            ]);
            if ($attempt->rowCount() !== 1) {
                throw TaskJobException::internal();
            }
            $update = $this->pdo->prepare(<<<'SQL'
UPDATE pa_task_job
SET status = :status, available_at = UTC_TIMESTAMP(3), lease_owner_hash = NULL,
    lease_token_hash = NULL, lease_expires_at = NULL, last_error_code = 'TASK_LEASE_EXPIRED',
    completed_at = :completed_at, revision = revision + 1, updated_at = UTC_TIMESTAMP(3)
WHERE tenant_id = :tenant_id AND id = :id AND status = 'running'
  AND lease_token_hash = :lease_hash AND lease_expires_at <= UTC_TIMESTAMP(3)
SQL);
            $update->execute([
                'status' => $dead ? 'dead' : 'queued',
                'completed_at' => $dead ? $this->now() : null,
                'tenant_id' => $tenantId,
                'id' => $row['id'],
                'lease_hash' => $row['job_lease_token_hash'],
            ]);
            if ($update->rowCount() !== 1) {
                throw TaskJobException::stateConflict();
            }
            $this->insertEvent($tenantId, (int) $row['id'], $dead ? 'tenant.task.dead' : 'tenant.task.lease_recovered', null, [
                'attempt' => (int) $row['attempt_count'],
                'error_code' => 'TASK_LEASE_EXPIRED',
            ]);
        }
    }

    /** @return array<string, mixed>|null */
    private function rowById(int $tenantId, int $id, bool $lock): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pa_task_job WHERE tenant_id = :tenant_id AND id = :id' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute(['tenant_id' => $tenantId, 'id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @return array<string, mixed>|null */
    private function rowByJobKey(int $tenantId, string $jobKey, bool $lock): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pa_task_job WHERE tenant_id = :tenant_id AND job_key = :job_key' . ($lock ? ' FOR UPDATE' : ''));
        $statement->execute(['tenant_id' => $tenantId, 'job_key' => $jobKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string, bool|int|string|null> $metadata */
    private function insertEvent(int $tenantId, int $jobId, string $event, ?int $memberId, array $metadata): void
    {
        try {
            $json = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw TaskJobException::internal();
        }
        $statement = $this->pdo->prepare(<<<'SQL'
INSERT INTO pa_task_job_event (tenant_id, job_id, event_key, actor_member_id, metadata_json, occurred_at)
VALUES (:tenant_id, :job_id, :event_key, :actor_member_id, :metadata_json, UTC_TIMESTAMP(3))
SQL);
        $statement->execute([
            'tenant_id' => $tenantId,
            'job_id' => $jobId,
            'event_key' => $event,
            'actor_member_id' => $memberId,
            'metadata_json' => $json,
        ]);
    }

    private function envelopeField(string $encoded, string $field): string
    {
        try {
            $document = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw TaskJobException::internal();
        }
        $value = is_array($document) && is_array($document['payload'] ?? null) ? ($document['payload'][$field] ?? null) : null;
        if (!is_string($value) || $value === '') {
            throw TaskJobException::internal();
        }
        return $value;
    }

    /** @return array<string, mixed>|null */
    private function idempotentRow(int $tenantId, int $memberId, string $taskType, string $keyHash, bool $lock): ?array
    {
        $statement = $this->pdo->prepare(<<<SQL
SELECT * FROM pa_task_job
WHERE tenant_id = :tenant_id AND created_by_member_id = :member_id
  AND task_type = :task_type AND idempotency_key_hash = :key_hash
LIMIT 1{$this->lock($lock)}
SQL);
        $statement->execute(['tenant_id' => $tenantId, 'member_id' => $memberId, 'task_type' => $taskType, 'key_hash' => $keyHash]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function lock(bool $lock): string
    {
        return $lock ? ' FOR UPDATE' : '';
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): JobRecord
    {
        return new JobRecord(
            (int) $row['id'],
            (string) $row['job_key'],
            (int) $row['tenant_id'],
            (string) $row['task_type'],
            (string) $row['status'],
            (int) $row['attempt_count'],
            (int) $row['max_attempts'],
            (int) $row['revision'],
            is_string($row['last_error_code']) ? $row['last_error_code'] : null,
            $this->timestamp($row['available_at']),
            $this->timestamp($row['created_at']),
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

    /** @param array<string, mixed> $row @param array<string, mixed> $payload */
    private function assertPayloadHash(array $row, array $payload): void
    {
        $stored = $row['payload_hash'] ?? null;
        if (!is_string($stored)
            || preg_match('/^[0-9a-f]{64}$/D', $stored) !== 1
            || !hash_equals($stored, $this->payloadHash($payload))
        ) {
            throw TaskJobException::internal();
        }
    }

    /** @param array<string, mixed> $payload */
    private function payloadHash(array $payload): string
    {
        try {
            return hash('sha256', json_encode($this->normalizePayload($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
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

    private function begin(): void
    {
        if ($this->pdo->inTransaction() || !$this->pdo->beginTransaction()) {
            throw TaskJobException::internal();
        }
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function now(): string
    {
        $statement = $this->pdo->query('SELECT UTC_TIMESTAMP(3)');
        $value = $statement === false ? false : $statement->fetchColumn();
        if (!is_string($value)) {
            throw TaskJobException::internal();
        }
        return $value;
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
