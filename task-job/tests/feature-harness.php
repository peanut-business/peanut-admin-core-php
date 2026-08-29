<?php

declare(strict_types=1);

use PeanutAdmin\Kernel\Async\AsyncAuthorizationRevalidator;
use PeanutAdmin\Kernel\Async\JobHandlerAdapter;
use PeanutAdmin\Kernel\Async\TrustedEnvelopeCodec;
use PeanutAdmin\Kernel\Async\VerifiedJobEnvelope;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;
use PeanutAdmin\TaskJob\Application\TaskJobException;
use PeanutAdmin\TaskJob\Application\TaskJobService;
use PeanutAdmin\TaskJob\Database\Schema;
use PeanutAdmin\TaskJob\Execution\JobExecution;
use PeanutAdmin\TaskJob\Execution\LocalWorker;
use PeanutAdmin\TaskJob\Execution\RetryableTaskException;
use PeanutAdmin\TaskJob\Execution\TaskHandler;
use PeanutAdmin\TaskJob\Execution\TaskHandlerRegistry;
use PeanutAdmin\TaskJob\Persistence\PdoTaskJobRepository;
use PeanutAdmin\TaskJob\Submission\TaskSubmission;
use PeanutAdmin\TaskJob\Submission\TaskSubmissionProvider;
use PeanutAdmin\TaskJob\Submission\TaskSubmissionRegistry;
use PeanutAdmin\TaskJob\Submission\TrustedJobPublisher;

$root = dirname(__DIR__, 4);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefixes = [
        'PeanutAdmin\\TaskJob\\' => $root . '/packages/php/task-job/src/',
        'PeanutAdmin\\Kernel\\' => $root . '/packages/php/kernel/src/',
    ];
    foreach ($prefixes as $prefix => $path) {
        if (str_starts_with($class, $prefix)) {
            $file = $path . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});

final class HarnessProvider implements TaskSubmissionProvider
{
    public function __construct(private readonly string $type = 'test.echo', private readonly string $handler = 'test.echo') {}
    public function taskType(): string
    {
        return $this->type;
    }
    public function resourceKey(): string
    {
        return 'test.message';
    }
    public function operation(): string
    {
        return 'send';
    }
    public function build(AuthorizedOperationContext $context, array $input): TaskSubmission
    {
        if (array_keys($input) !== ['message'] || !is_string($input['message']) || $input['message'] === '' || strlen($input['message']) > 64) {
            throw TaskJobException::invalid();
        }
        return new TaskSubmission($this->handler, ['message' => $input['message']], 3);
    }
}

final class HarnessRevalidator implements AsyncAuthorizationRevalidator
{
    public function __construct(private readonly ?string $drift = null) {}

    public function reauthorize(VerifiedJobEnvelope $envelope): AuthorizedOperationContext
    {
        $tenantId = $envelope->tenantId;
        $accountId = $envelope->accountId;
        $memberId = $envelope->memberId;
        $resource = $envelope->resourceKey;
        $operation = $envelope->operation;
        $targets = $envelope->requestedTargets;
        match ($this->drift) {
            'tenant' => $tenantId += 1,
            'account' => $accountId += 1,
            'member' => $memberId += 1,
            'resource' => $resource .= '.drift',
            'operation' => $operation .= '.drift',
            'targets' => $targets[] = new RequestedTargetSet('test.object', ['object-b', 'object-a']),
            'reorder_targets' => $targets = array_reverse($targets),
            null => null,
            default => throw new RuntimeException('Unknown harness drift.'),
        };
        return context($tenantId, $resource, $operation, $memberId, $accountId, $targets);
    }
}

final class HarnessHandler implements TaskHandler
{
    public int $calls = 0;
    public function key(): string
    {
        return 'test.echo';
    }
    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        ++$this->calls;
        assertSame('test.message', $context->resourceKey, 'worker revalidates producer resource');
        assertSame(['message'], array_keys($execution->payload), 'handler receives provider-built payload only');
        if (!str_starts_with($execution->jobKey, 'job_') || $execution->tenantId !== 101 || $execution->attemptNumber !== $this->calls) {
            throw new RuntimeException('Handler did not receive trusted execution idempotency evidence.');
        }
        if ($this->calls === 1) {
            throw new RetryableTaskException('TEST_TRANSIENT');
        }
    }
}

final class HarnessSuccessHandler implements TaskHandler
{
    public int $calls = 0;
    public function key(): string
    {
        return 'test.echo';
    }
    public function handle(AuthorizedOperationContext $context, JobExecution $execution): void
    {
        ++$this->calls;
    }
}

/** @param list<RequestedTargetSet> $targets */
function context(
    int $tenantId,
    string $resource,
    string $operation,
    int $memberId = 501,
    ?int $accountId = null,
    array $targets = [],
): AuthorizedOperationContext {
    $tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $tenantId,
        'session-' . $tenantId,
        $tenantId,
        $accountId ?? $tenantId + 10,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2026-07-24T00:00:00Z'),
        1,
    ), 'request-' . $tenantId);
    return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow($tenant, $resource, $operation, $targets, 'basis-' . $tenantId));
}

function assertSame(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function expectProblem(string $code, callable $operation, string $label): void
{
    try {
        $operation();
    } catch (TaskJobException $exception) {
        assertSame($code, $exception->problemCode, $label);
        return;
    }
    throw new RuntimeException($label . ': expected ' . $code);
}

function expectRuntime(string $code, callable $operation, string $label): void
{
    try {
        $operation();
    } catch (RuntimeException $exception) {
        assertSame($code, $exception->getMessage(), $label);
        return;
    }
    throw new RuntimeException($label . ': expected ' . $code);
}

$host = getenv('TASK_JOB_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('TASK_JOB_MYSQL_PORT') ?: '33421';
$database = getenv('TASK_JOB_MYSQL_DATABASE') ?: 'peanut_task_job_test';
$user = getenv('TASK_JOB_MYSQL_USER') ?: 'root';
$password = getenv('TASK_JOB_MYSQL_PASSWORD') ?: 'task-job-test';
$run = static function (TenantPersistenceMode $mode) use ($host, $port, $database, $user, $password): void {
$pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
if (preg_match('/^[a-z][a-z0-9_]{2,62}$/D', $database) !== 1) {
    throw new RuntimeException('Unsafe test database.');
}
$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$pdo->exec("USE `{$database}`");
$pdo->exec('CREATE TABLE pa_tenant (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant_member (
  id BIGINT UNSIGNED NOT NULL, tenant_id BIGINT UNSIGNED NOT NULL, account_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_tenant_member (tenant_id, id)
) ENGINE=InnoDB
SQL);
$pdo->exec("INSERT INTO pa_tenant VALUES (101), (202)");
$pdo->exec("INSERT INTO pa_tenant_member VALUES (501, 101, 111, 'active'), (502, 202, 212, 'active')");
foreach (Schema::tableNames() as $table) {
    $pdo->exec(Schema::createSql($table, $mode));
}
if ($mode === TenantPersistenceMode::InstanceScoped) {
    foreach (Schema::tableNames() as $table) {
        foreach (['COLUMNS', 'STATISTICS', 'KEY_COLUMN_USAGE'] as $informationSchemaTable) {
            $count = $pdo->query(<<<SQL
SELECT COUNT(*) FROM information_schema.{$informationSchemaTable}
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$table}' AND COLUMN_NAME = 'tenant_id'
SQL)->fetchColumn();
            assertSame(0, (int) $count, "{$table} has no tenant_id in {$informationSchemaTable}");
        }
    }
}

$codec = new TrustedEnvelopeCodec('task-job-harness-signing-key-32-bytes-minimum');
$registry = new TaskSubmissionRegistry([new HarnessProvider(), new HarnessProvider('test.missing', 'test.missing')]);
$repository = new PdoTaskJobRepository(
    $pdo,
    $mode,
    $mode === TenantPersistenceMode::InstanceScoped ? 101 : null,
);
$publisher = new TrustedJobPublisher($repository, $registry, $codec);
$admin = new TaskJobService($repository);
$producer101 = context(101, 'test.message', 'send', 501);
$producer202 = context(202, 'test.message', 'send', 502);

$job = $publisher->publish($producer101, 'test.echo', ['message' => 'hello'], 'idem-0001');
$replay = $publisher->publish($producer101, 'test.echo', ['message' => 'hello'], 'idem-0001');
assertSame($job->jobKey, $replay->jobKey, 'exact idempotency replay');
expectProblem('TASK_IDEMPOTENCY_CONFLICT', fn() => $publisher->publish($producer101, 'test.echo', ['message' => 'changed'], 'idem-0001'), 'idempotency payload conflict');
expectProblem('TASK_PERMISSION_DENIED', fn() => $publisher->publish(context(101, 'test.message', 'read'), 'test.echo', ['message' => 'hello'], 'idem-wrong'), 'producer operation mismatch');
$pdo->beginTransaction();
$transactional = $publisher->publish($producer101, 'test.echo', ['message' => 'rollback'], 'idem-rollback');
$pdo->rollBack();
expectProblem('TASK_NOT_FOUND', fn() => $admin->detail(context(101, TaskJobService::RESOURCE_KEY, 'read'), $transactional->jobKey), 'outer business rollback removes job and event');
if ($mode === TenantPersistenceMode::TenantScoped) {
    $tenant2 = $publisher->publish($producer202, 'test.echo', ['message' => 'tenant two'], 'idem-0001');
    assertSame(202, $tenant2->tenantId, 'idempotency is tenant scoped');
    assertSame(1, $admin->list(context(101, TaskJobService::RESOURCE_KEY, 'read'), 'queued', 1, 20)['total'], 'tenant 101 list');
    assertSame(1, $admin->list(context(202, TaskJobService::RESOURCE_KEY, 'read', 502), 'queued', 1, 20)['total'], 'tenant 202 list');
    expectProblem('TASK_NOT_FOUND', fn() => $admin->detail(context(202, TaskJobService::RESOURCE_KEY, 'read', 502), $job->jobKey), 'cross-tenant detail');
} else {
    expectRuntime(
        'TENANT_PERSISTENCE_CONTEXT_INVALID',
        fn() => $publisher->publish($producer202, 'test.echo', ['message' => 'tenant two'], 'idem-0001'),
        'instance scope rejects another logical tenant',
    );
    assertSame(1, $admin->list(context(101, TaskJobService::RESOURCE_KEY, 'read'), 'queued', 1, 20)['total'], 'instance list');
}
expectProblem('TASK_PERMISSION_DENIED', fn() => $admin->list(context(101, TaskJobService::RESOURCE_KEY, 'manage'), 'queued', 1, 20), 'read permission operation');

$handler = new HarnessHandler();
$worker = new LocalWorker(101, 'worker-local-01', $repository, new TaskHandlerRegistry([$handler]), new JobHandlerAdapter($codec, new HarnessRevalidator()), 30);
assertSame('queued', $worker->runOnce(), 'retryable failure schedules retry');
$pdo->exec("UPDATE pa_task_job SET available_at = UTC_TIMESTAMP(3) WHERE job_key = " . $pdo->quote($job->jobKey));
assertSame('succeeded', $worker->runOnce(), 'bounded retry succeeds');
assertSame(2, $handler->calls, 'handler called twice');

$stale = $publisher->publish($producer101, 'test.echo', ['message' => 'lease'], 'idem-lease');
$claim = $repository->claim(101, 'worker-stale', 30);
assertSame($stale->jobKey, $claim?->jobKey, 'atomic claim returns queued job');
$secondPdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
assertSame(null, (new PdoTaskJobRepository(
    $secondPdo,
    $mode,
    $mode === TenantPersistenceMode::InstanceScoped ? 101 : null,
))->claim(101, 'worker-other', 30), 'claimed job is excluded from a second worker');
$pdo->exec("UPDATE pa_task_job SET lease_expires_at = TIMESTAMPADD(SECOND, -1, UTC_TIMESTAMP(3)) WHERE job_key = " . $pdo->quote($stale->jobKey));
$recovered = $repository->claim(101, 'worker-recovery', 30);
assertSame(2, $recovered?->attemptNumber, 'expired lease is recovered as a new attempt');
expectProblem('TASK_STATE_CONFLICT', fn() => $repository->succeed($claim), 'stale lease token is fenced');
$repository->succeed($recovered);

foreach (['tenant', 'account', 'member', 'resource', 'operation', 'targets'] as $drift) {
    $driftJob = $publisher->publish($producer101, 'test.echo', ['message' => 'drift-' . $drift], 'idem-drift-' . $drift);
    $beforeCalls = $handler->calls;
    $driftWorker = new LocalWorker(
        101,
        'worker-drift-' . $drift,
        $repository,
        new TaskHandlerRegistry([$handler]),
        new JobHandlerAdapter($codec, new HarnessRevalidator($drift)),
        30,
    );
    assertSame('dead', $driftWorker->runOnce(), 'revalidated ' . $drift . ' drift fails closed');
    assertSame($beforeCalls, $handler->calls, $drift . ' drift never reaches handler');
    assertSame('TASK_PERMISSION_DENIED', $admin->detail(context(101, TaskJobService::RESOURCE_KEY, 'read'), $driftJob->jobKey)->lastErrorCode, $drift . ' drift error');
}

$targetContext = context(101, 'test.message', 'send', 501, 111, [
    new RequestedTargetSet('test.second', ['b', 'a']),
    new RequestedTargetSet('test.first', ['2', '1']),
]);
$targetJob = $publisher->publish($targetContext, 'test.echo', ['message' => 'target-order'], 'idem-target-order');
$successHandler = new HarnessSuccessHandler();
$targetWorker = new LocalWorker(
    101,
    'worker-target-order',
    $repository,
    new TaskHandlerRegistry([$successHandler]),
    new JobHandlerAdapter($codec, new HarnessRevalidator('reorder_targets')),
    30,
);
assertSame('succeeded', $targetWorker->runOnce(), 'target set ordering is normalized');
assertSame(1, $successHandler->calls, 'normalized target context reaches handler');
assertSame('succeeded', $admin->detail(context(101, TaskJobService::RESOURCE_KEY, 'read'), $targetJob->jobKey)->status, 'normalized target job');

$payloadCorrupt = $publisher->publish($producer101, 'test.echo', ['message' => 'integrity'], 'idem-payload-integrity');
$pdo->exec("UPDATE pa_task_job SET payload_json = JSON_OBJECT('message', 'tampered') WHERE job_key = " . $pdo->quote($payloadCorrupt->jobKey));
expectProblem('TASK_INTERNAL_ERROR', fn() => $repository->claim(101, 'worker-payload-corrupt', 30), 'payload digest corruption fails claim');
assertSame('queued', $admin->detail(context(101, TaskJobService::RESOURCE_KEY, 'read'), $payloadCorrupt->jobKey)->status, 'payload corruption does not claim job');
$pdo->exec("UPDATE pa_task_job SET payload_json = JSON_OBJECT('message', 'integrity') WHERE job_key = " . $pdo->quote($payloadCorrupt->jobKey));
$payloadClaim = $repository->claim(101, 'worker-payload-repaired', 30);
assertSame($payloadCorrupt->jobKey, $payloadClaim?->jobKey, 'repaired payload is claimable');
$repository->succeed($payloadClaim);

$attemptCorrupt = $publisher->publish($producer101, 'test.echo', ['message' => 'attempt-integrity'], 'idem-attempt-integrity');
$attemptClaim = $repository->claim(101, 'worker-attempt-corrupt', 30);
assertSame($attemptCorrupt->jobKey, $attemptClaim?->jobKey, 'attempt corruption fixture claimed');
$pdo->exec("UPDATE pa_task_job SET lease_expires_at = TIMESTAMPADD(SECOND, -1, UTC_TIMESTAMP(3)) WHERE id = {$attemptClaim->id}");
$pdo->exec("UPDATE pa_task_job_attempt SET lease_token_hash = REPEAT('b', 64) WHERE job_id = {$attemptClaim->id} AND attempt_number = {$attemptClaim->attemptNumber}");
expectProblem('TASK_INTERNAL_ERROR', fn() => $repository->claim(101, 'worker-attempt-mismatch', 30), 'job and attempt lease digest mismatch fails recovery');
assertSame('running', $admin->detail(context(101, TaskJobService::RESOURCE_KEY, 'read'), $attemptCorrupt->jobKey)->status, 'attempt mismatch does not recover job');
$pdo->exec("UPDATE pa_task_job_attempt attempt JOIN pa_task_job job ON job.id = attempt.job_id SET attempt.lease_token_hash = job.lease_token_hash WHERE job.id = {$attemptClaim->id} AND attempt.attempt_number = {$attemptClaim->attemptNumber}");
$attemptRecovered = $repository->claim(101, 'worker-attempt-repaired', 30);
assertSame(2, $attemptRecovered?->attemptNumber, 'matching lease digests allow expired recovery');
$repository->succeed($attemptRecovered);

$missing = $publisher->publish($producer101, 'test.missing', ['message' => 'missing'], 'idem-missing');
$missingWorker = new LocalWorker(101, 'worker-missing', $repository, new TaskHandlerRegistry(), new JobHandlerAdapter($codec, new HarnessRevalidator()), 30);
assertSame('dead', $missingWorker->runOnce(), 'unknown handler fails closed');
assertSame('TASK_HANDLER_UNAVAILABLE', $admin->detail(context(101, TaskJobService::RESOURCE_KEY, 'read'), $missing->jobKey)->lastErrorCode, 'handler error is stable and redacted');

$cancel = $publisher->publish($producer101, 'test.echo', ['message' => 'cancel'], 'idem-cancel');
$cancelled = $admin->cancel(context(101, TaskJobService::RESOURCE_KEY, 'manage'), $cancel->jobKey, $cancel->revision);
assertSame('cancelled', $cancelled->status, 'queued cancellation');
expectProblem('TASK_STATE_CONFLICT', fn() => $admin->cancel(context(101, TaskJobService::RESOURCE_KEY, 'manage'), $cancel->jobKey, $cancel->revision), 'optimistic revision fences replay');

$raw = (string) $pdo->query('SELECT JSON_ARRAYAGG(JSON_OBJECT(\'event\', event_key, \'metadata\', metadata_json)) FROM pa_task_job_event')->fetchColumn();
if (str_contains($raw, 'trusted_envelope') || str_contains($raw, 'payload') || str_contains($raw, 'idem-') || str_contains($raw, 'hello')) {
    throw new RuntimeException('Audit events exposed internal payload or idempotency material.');
}
assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM pa_task_job_attempt WHERE job_id = (SELECT id FROM pa_task_job WHERE job_key = " . $pdo->quote($job->jobKey) . ')')->fetchColumn(), 'retry attempt ledger');
assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM pa_task_job_attempt WHERE status = 'abandoned'")->fetchColumn(), 'expired attempt ledger');
if ($mode === TenantPersistenceMode::TenantScoped) {
    $attemptsBeforeMismatch = (int) $pdo->query('SELECT COUNT(*) FROM pa_task_job_attempt')->fetchColumn();
    expectRuntime(
        'TENANT_PERSISTENCE_SCHEMA_MODE_MISMATCH',
        fn() => (new PdoTaskJobRepository(
            $pdo,
            TenantPersistenceMode::InstanceScoped,
            101,
        ))->claim(101, 'worker-schema-mismatch', 30),
        'instance repository rejects tenant schema before claim',
    );
    assertSame($attemptsBeforeMismatch, (int) $pdo->query('SELECT COUNT(*) FROM pa_task_job_attempt')->fetchColumn(), 'schema mismatch creates no attempt');
    assertSame('queued', $admin->detail(context(202, TaskJobService::RESOURCE_KEY, 'read', 502), $tenant2->jobKey)->status, 'schema mismatch does not claim another tenant job');
}

foreach (array_reverse(Schema::tableNames()) as $table) {
    $pdo->exec(Schema::dropSql($table));
}
$pdo->exec('DROP TABLE pa_tenant_member, pa_tenant');
$pdo->exec("DROP DATABASE `{$database}`");
fwrite(STDOUT, "task-job {$mode->value} PASS (idempotency, permission, claim/lease, retry, recovery, audit)\n");
};

foreach ([TenantPersistenceMode::TenantScoped, TenantPersistenceMode::InstanceScoped] as $mode) {
    $run($mode);
}
