<?php

declare(strict_types=1);

use PeanutAdmin\ImportExport\Application\ImportExportException;
use PeanutAdmin\ImportExport\Application\ImportExportService;
use PeanutAdmin\ImportExport\Contract\ColumnDefinition;
use PeanutAdmin\ImportExport\Contract\DataProvider;
use PeanutAdmin\ImportExport\Contract\DataProviderRegistry;
use PeanutAdmin\ImportExport\Contract\ExportBatch;
use PeanutAdmin\ImportExport\Contract\RowIssue;
use PeanutAdmin\ImportExport\Contract\SchemaDefinition;
use PeanutAdmin\ImportExport\Database\Schema;
use PeanutAdmin\ImportExport\Execution\CsvOperationRunner;
use PeanutAdmin\ImportExport\File\FileMediaGateway;
use PeanutAdmin\ImportExport\Persistence\PdoImportExportRepository;
use PeanutAdmin\Kernel\Audit\AuditRepository;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

$root = dirname(__DIR__, 4);
spl_autoload_register(static function (string $class) use ($root): void {
    foreach ([
        'PeanutAdmin\\ImportExport\\' => $root . '/packages/php/import-export/src/',
        'PeanutAdmin\\Kernel\\' => $root . '/packages/php/kernel/src/',
    ] as $prefix => $path) {
        if (str_starts_with($class, $prefix)) {
            $file = $path . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    }
});

function check(mixed $expected, mixed $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
function problem(string $code, callable $operation, string $label): void
{
    try {
        $operation();
    } catch (ImportExportException $exception) {
        check($code, $exception->problemCode, $label);
        return;
    }
    throw new RuntimeException($label . ': expected ' . $code);
}
function context(int $tenantId, int $memberId, string $operation): AuthorizedOperationContext
{
    $tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
        $tenantId,
        'session-' . $tenantId,
        $tenantId,
        $tenantId + 10,
        $memberId,
        'admin-web',
        new DateTimeImmutable('2026-07-24T00:00:00Z'),
        1,
    ), 'request-' . $tenantId);
    return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow($tenant, ImportExportService::RESOURCE_KEY, $operation, [], 'basis-' . $tenantId));
}

final class HarnessProvider implements DataProvider
{
    /** @var list<array<string, string|null>> */
    public array $imported = [];
    public ?Closure $duringImport = null;
    public function key(): string
    {
        return 'test.contacts';
    }
    public function schema(): SchemaDefinition
    {
        return new SchemaDefinition('contacts.v1', [new ColumnDefinition('name', 'Name', requiredOnImport: true, maxBytes: 64), new ColumnDefinition('email', 'Email', maxBytes: 120), new ColumnDefinition('formula_header', '=Formula', importable: false)]);
    }
    public function validateImport(AuthorizedOperationContext $context, array $row): array
    {
        return isset($row['email']) && $row['email'] !== null && !str_contains($row['email'], '@') ? [new RowIssue('CONTACT_EMAIL_INVALID', 'email')] : [];
    }
    public function importRow(AuthorizedOperationContext $context, array $row, string $idempotencyKey): void
    {
        if ($this->duringImport !== null) {
            ($this->duringImport)();
        } $this->imported[] = $row + ['idempotency' => $idempotencyKey];
    }
    public function exportBatch(AuthorizedOperationContext $context, ?string $cursor, int $limit): ExportBatch
    {
        return $cursor === null ? new ExportBatch([['name' => "\xEF\xBB\xBF \t=Alice", 'email' => 'alice@example.test', 'formula_header' => "\t@payload"], ['name' => "\r-Bob", 'email' => null, 'formula_header' => '+formula']], null) : throw new RuntimeException('unexpected cursor');
    }
}

final class HarnessFiles implements FileMediaGateway
{
    /** @var array<string, string> */ public array $inputs = [];
    /** @var array<string, string> */ public array $outputs = [];
    public function openCsvInput(AuthorizedOperationContext $context, string $fileKey)
    {
        if (!isset($this->inputs[$fileKey])) {
            throw ImportExportException::fileUnavailable();
        }
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $this->inputs[$fileKey]);
        rewind($stream);
        return $stream;
    }
    public function storePrivateCsv(AuthorizedOperationContext $context, string $operationKey, string $purpose, string $filename, $stream): string
    {
        $content = stream_get_contents($stream);
        if (!is_string($content)) {
            throw ImportExportException::fileUnavailable();
        }
        $key = 'file_' . substr(hash('sha256', $operationKey . '|' . $purpose), 0, 32);
        $this->outputs[$key] = $content;
        return $key;
    }
}

final class HarnessAudit implements AuditRepository
{
    /** @var list<string> */ public array $events = [];
    public function appendPlatform(string $eventType, string $action, string $requestId, ?int $operatorId, ?int $accountId, array $metadata = []): void
    {
        throw new RuntimeException('platform audit not expected');
    }
    public function appendTenantSystem(int $tenantId, string $eventType, string $action, string $requestId, array $metadata = []): void
    {
        throw new RuntimeException('system audit not expected');
    }
    public function appendTenantMember(TenantContext $context, string $eventType, string $action, ?string $targetResourceType = null, ?string $targetResourceId = null, ?string $boundaryTargetType = null, ?string $boundaryTargetId = null, int $targetCount = 0, ?string $targetSetDigest = null, array $metadata = []): void
    {
        $this->events[] = $eventType . ':' . implode(',', array_keys($metadata));
    }
    public function appendTenantPlatformOperator(int $tenantId, int $operatorId, int $accountId, string $eventType, string $action, string $requestId, array $metadata = []): void
    {
        throw new RuntimeException('platform audit not expected');
    }
}

$host = getenv('IMPORT_EXPORT_MYSQL_HOST') ?: '127.0.0.1';
$port = getenv('IMPORT_EXPORT_MYSQL_PORT') ?: '33431';
$database = getenv('IMPORT_EXPORT_MYSQL_DATABASE') ?: 'peanut_import_export_test';
$user = getenv('IMPORT_EXPORT_MYSQL_USER') ?: 'root';
$password = getenv('IMPORT_EXPORT_MYSQL_PASSWORD') ?: 'import-export-test';
if (preg_match('/^[a-z][a-z0-9_]{2,62}$/D', $database) !== 1) {
    throw new RuntimeException('Unsafe test database.');
}
$pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
$pdo->exec("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci");
$pdo->exec("USE `{$database}`");
$pdo->exec('CREATE TABLE pa_tenant (id BIGINT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
$pdo->exec('CREATE TABLE pa_tenant_member (id BIGINT UNSIGNED NOT NULL, tenant_id BIGINT UNSIGNED NOT NULL, account_id BIGINT UNSIGNED NOT NULL, status VARCHAR(16) NOT NULL, PRIMARY KEY (id), UNIQUE KEY uk_member_tenant (tenant_id,id)) ENGINE=InnoDB');
$pdo->exec("INSERT INTO pa_tenant VALUES (101),(202)");
$pdo->exec("INSERT INTO pa_tenant_member VALUES (501,101,111,'active'),(502,202,212,'active')");
foreach (Schema::tableNames() as $table) {
    $pdo->exec(Schema::createSql($table));
}

$repository = new PdoImportExportRepository($pdo);
$provider = new HarnessProvider();
$files = new HarnessFiles();
$audit = new HarnessAudit();
$registry = new DataProviderRegistry([$provider]);
$runner = new CsvOperationRunner($repository, $registry, $files, $audit);
$create101 = context(101, 501, 'create');
$read101 = context(101, 501, 'read');
ImportExportService::assertOperation($create101, 'create');
problem('IMPORT_EXPORT_PERMISSION_DENIED', fn() => ImportExportService::assertOperation($read101, 'create'), 'permission operation fails closed');

$fileKey = 'file_' . str_repeat('a', 32);
$files->inputs[$fileKey] = "Name,Email\r\nAlice,alice@example.test\r\nBob,invalid\r\n,empty@example.test\r\n";
$mapping = $provider->schema()->validateImportMapping(['Name' => 'name', 'Email' => 'email']);
$import = $repository->create(101, 501, 'iox_' . str_repeat('1', 32), $provider->key(), 'import', $fileKey, 'contacts.v1', $mapping, hash('sha256', 'idem-import'), hash('sha256', 'request-import'), 7);
$replay = $repository->create(101, 501, 'iox_' . str_repeat('2', 32), $provider->key(), 'import', $fileKey, 'contacts.v1', $mapping, hash('sha256', 'idem-import'), hash('sha256', 'request-import'), 7);
check($import->operationKey, $replay->operationKey, 'idempotency replay');
problem('IMPORT_EXPORT_IDEMPOTENCY_CONFLICT', fn() => $repository->create(101, 501, 'iox_' . str_repeat('3', 32), $provider->key(), 'import', $fileKey, 'contacts.v1', $mapping, hash('sha256', 'idem-import'), hash('sha256', 'changed'), 7), 'idempotency payload conflict');
problem('IMPORT_EXPORT_NOT_FOUND', fn() => $repository->get(202, $import->operationKey), 'cross tenant detail indistinguishable');
$import = $repository->attachJob(101, $import->operationKey, 'job_' . str_repeat('a', 32));
$import = $runner->run($create101, $import->operationKey, $import->taskJobKey ?? '', 1);
check('succeeded', $import->status, 'import completion');
check(3, $import->processedRows, 'import progress');
check(1, $import->acceptedRows, 'accepted rows');
check(2, $import->rejectedRows, 'rejected rows');
check(1, count($provider->imported), 'provider called only for valid row');
check(2, count($repository->rowIssues(101, $import->id)), 'redacted row errors');
if ($import->errorFileKey === null || str_contains($files->outputs[$import->errorFileKey], 'invalid') || str_contains($files->outputs[$import->errorFileKey], 'empty@example')) {
    throw new RuntimeException('Error report leaked rejected values.');
}
problem('IMPORT_EXPORT_STATE_CONFLICT', fn() => $runner->run($create101, $import->operationKey, $import->taskJobKey ?? '', 2), 'terminal operation cannot be reclaimed');

$export = $repository->create(101, 501, 'iox_' . str_repeat('4', 32), $provider->key(), 'export', null, 'contacts.v1', [], hash('sha256', 'idem-export'), hash('sha256', 'request-export'), 7);
$export = $repository->attachJob(101, $export->operationKey, 'job_' . str_repeat('b', 32));
$export = $runner->run($create101, $export->operationKey, $export->taskJobKey ?? '', 1);
check('succeeded', $export->status, 'export completion');
check(2, $export->processedRows, 'export rows');
if ($export->resultFileKey === null) {
    throw new RuntimeException('Export result missing.');
}
$csv = $files->outputs[$export->resultFileKey];
foreach (["'=Formula", "'\xEF\xBB\xBF \t=Alice", "'\t@payload", "'\r-Bob", "'+formula"] as $safeText) {
    if (!str_contains($csv, $safeText)) {
        throw new RuntimeException('CSV formula was not neutralized: ' . bin2hex($safeText));
    }
}
check(['tenant.import_export.started:direction,provider_key,revision,attempt', 'tenant.import_export.progress:direction,provider_key,revision,processed_rows,accepted_rows,rejected_rows', 'tenant.import_export.succeeded:direction,provider_key,revision,processed_rows,accepted_rows,rejected_rows', 'tenant.import_export.started:direction,provider_key,revision,attempt', 'tenant.import_export.succeeded:direction,provider_key,revision,processed_rows,accepted_rows,rejected_rows'], $audit->events, 'redacted lifecycle audit');

problem('IMPORT_EXPORT_SCHEMA_MISMATCH', fn() => $provider->schema()->normalizeImportRow(["\xC3\x28"], ['Name'], ['Name' => 'name']), 'invalid UTF-8 import cell');
problem('IMPORT_EXPORT_SCHEMA_MISMATCH', fn() => $provider->schema()->normalizeImportRow(['Alice', "\xC3\x28"], ['Name', 'Ignored'], ['Name' => 'name']), 'invalid UTF-8 unmapped import cell');
problem('IMPORT_EXPORT_SCHEMA_MISMATCH', fn() => $provider->schema()->exportValues(['name' => "\xC3\x28", 'email' => null, 'formula_header' => 'safe']), 'invalid UTF-8 export text');
problem('IMPORT_EXPORT_INVALID', fn() => new ColumnDefinition('invalid_heading', "\xC3\x28"), 'invalid UTF-8 schema heading');

$cancel = $repository->create(101, 501, 'iox_' . str_repeat('5', 32), $provider->key(), 'export', null, 'contacts.v1', [], hash('sha256', 'idem-cancel'), hash('sha256', 'request-cancel'), 1);
$cancel = $repository->requestCancel(101, $cancel->operationKey, $cancel->revision);
check('cancelled', $cancel->status, 'queued cancellation');

$racingFileKey = 'file_' . str_repeat('b', 32);
$files->inputs[$racingFileKey] = "Name,Email\r\nRace,race@example.test\r\n";
$racing = $repository->create(101, 501, 'iox_' . str_repeat('6', 32), $provider->key(), 'import', $racingFileKey, 'contacts.v1', $mapping, hash('sha256', 'idem-racing'), hash('sha256', 'request-racing'), 1);
$racing = $repository->attachJob(101, $racing->operationKey, 'job_' . str_repeat('c', 32));
$provider->duringImport = static function () use ($repository, &$racing): void {
    $current = $repository->get(101, $racing->operationKey);
    $repository->requestCancel(101, $racing->operationKey, $current->revision);
};
$racing = $runner->run($create101, $racing->operationKey, $racing->taskJobKey ?? '', 1);
$provider->duringImport = null;
check('cancelled', $racing->status, 'provider/progress cancellation race settles without runner failure');
check(1, $racing->processedRows, 'cancel race progress checkpoint');

$finishRace = $repository->create(101, 501, 'iox_' . str_repeat('7', 32), $provider->key(), 'export', null, 'contacts.v1', [], hash('sha256', 'idem-finish-race'), hash('sha256', 'request-finish-race'), 1);
$finishRace = $repository->attachJob(101, $finishRace->operationKey, 'job_' . str_repeat('e', 32));
$finishRace = $repository->beginAttempt(101, $finishRace->operationKey, $finishRace->taskJobKey ?? '', 1);
$finishRace = $repository->requestCancel(101, $finishRace->operationKey, $finishRace->revision);
$finishRace = $repository->finish(101, $finishRace->id, $finishRace->taskJobKey ?? '', 1, 'succeeded', 'file_' . str_repeat('f', 32), null, 0);
check('cancelled', $finishRace->status, 'finish/cancel race prefers cancellation');
check(null, $finishRace->resultFileKey, 'cancelled finish publishes no result');
$pdo->exec("UPDATE pa_import_export_operation SET retention_until = TIMESTAMPADD(SECOND,-1,UTC_TIMESTAMP(3)) WHERE operation_key = " . $pdo->quote($cancel->operationKey));
check(1, $repository->expireDue(), 'retention expiry');
check('expired', $repository->get(101, $cancel->operationKey)->status, 'expired terminal state');

foreach (array_reverse(Schema::tableNames()) as $table) {
    $pdo->exec(Schema::dropSql($table));
} $pdo->exec('DROP TABLE pa_tenant_member, pa_tenant');
$pdo->exec("DROP DATABASE `{$database}`");
fwrite(STDOUT, "import-export feature harness PASS (migration, tenant, permission, idempotency, CSV, row errors, concurrency, retention)\n");
