<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Host;

use DateTimeImmutable;
use PDO;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\Kernel\Api\ApiException;
use PeanutAdmin\Kernel\Api\RequestId;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\DataPermissionAdapter;
use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;
use PeanutAdmin\Kernel\Authorization\PermissionRequirement;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use PeanutAdmin\Kernel\Host\AtomicOperationAdapter;
use PeanutAdmin\Kernel\Host\AuthorizedExternalOperation;
use PeanutAdmin\Kernel\Host\ExternalHostConfiguration;
use PeanutAdmin\Kernel\Host\ExternalOperationDefinition;
use PeanutAdmin\Kernel\Host\ExternalOperationHost;
use PeanutAdmin\Kernel\Host\ExternalOperationRequest;
use PeanutAdmin\Kernel\Host\ExternalOperationResponse;
use PeanutAdmin\Kernel\Host\ExternalOperationResult;
use PeanutAdmin\Kernel\Host\ModuleAvailabilityAdapter;
use PeanutAdmin\Kernel\Host\PermissionAdapter;
use PeanutAdmin\Kernel\Host\ProblemDetailsAdapter;
use PeanutAdmin\Kernel\Host\TrustedContextAdapter;
use PeanutAdmin\Kernel\Host\TypedTargetAdapter;
use PeanutAdmin\Kernel\Http\PermissionMiddleware;
use PeanutAdmin\Kernel\Idempotency\IdempotencyKey;
use PeanutAdmin\Kernel\Idempotency\PdoIdempotencyRepository;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleGuard;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;
use PeanutAdmin\Kernel\Module\Persistence\PdoModuleRuntimeRepository;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationRepository;
use PeanutAdmin\Kernel\Tests\Integration\Schema\KernelMigrationRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__) . '/Schema/KernelMigrationRunner.php';

final class ExternalOperationHostIntegrationTest extends TestCase
{
    private const DATABASE = 'peanut_admin_p1_r02_external_host_test';

    private PDO $admin;
    private PDO $database;
    private ExternalOperationHost $host;

    /** @var array<int, int> */
    private array $memberIds = [];

    /** @var array<int, int> */
    private array $accountIds = [];

    /** @var array<int, int> */
    private array $scopeIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run with the isolated R02 MySQL environment.');
        }

        $this->admin = $this->connect();
        $this->admin->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        $this->admin->exec(
            'CREATE DATABASE `' . self::DATABASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
        );
        $this->database = $this->connect(self::DATABASE);
        (new KernelMigrationRunner(
            self::DATABASE,
            '127.0.0.1',
            (int) (getenv('MYSQL_PORT') ?: 33382),
            'root',
            getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev',
        ))->migrate();
        $this->createFixtureSchema();
        $this->seedFixture();
        $this->host = $this->host();
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            $this->admin->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        }
        parent::tearDown();
    }

    public function testFictionalFiveOperationsAreTenantIsolatedAndIdempotent(): void
    {
        $tenantId = 1;
        $scopeId = (string) $this->scopeIds[$tenantId];
        $create = $this->definition(
            'fixtureRecordCreate',
            'POST',
            '/api/v1/fixture/records',
            'create',
            true,
        );
        $createRequest = $this->request(
            $tenantId,
            'req_r02_create_0001',
            'POST',
            '/api/v1/fixture/records',
            ['name' => 'First record'],
            [$this->oneTarget($scopeId)],
            '01KPEANUT-R02-CREATE-0001',
        );
        $createHandler = function (
            AuthorizedExternalOperation $authorized,
            ExternalOperationRequest $request,
            PDO $pdo,
        ): ExternalOperationResult {
            $context = $this->tenantContext($authorized);
            $statement = $pdo->prepare(
                "INSERT INTO fixture_record (tenant_id, scope_id, name, status, revision) VALUES (:tenant, :scope, :name, 'draft', 1)",
            );
            $statement->execute([
                'tenant' => $context->tenantId,
                'scope' => (int) $authorized->targets[0]->targetIds[0],
                'name' => (string) $request->body['name'],
            ]);
            $id = (string) $pdo->lastInsertId();
            return new ExternalOperationResult(
                201,
                ['data' => ['id' => $id, 'name' => (string) $request->body['name']]],
                'fixture.record.created',
                'fixture.record.create',
                ['revision' => 1],
                'fixture.record',
                $id,
            );
        };
        $outbox = static function (PDO $pdo, ExternalOperationResult $result): void {
            $statement = $pdo->prepare('INSERT INTO fixture_outbox (event_key, resource_id) VALUES (:event, :resource)');
            $statement->execute(['event' => $result->auditEventType, 'resource' => $result->resourceId]);
        };

        $created = $this->host->command($create, $createRequest, $createHandler, $outbox);
        self::assertSame(201, $created->status);
        $recordId = (string) $created->body['data']['id'];
        $replayed = $this->host->command($create, $createRequest, $createHandler, $outbox);
        self::assertSame($created->body, $replayed->body);
        self::assertSame(1, $this->tableCount('fixture_record'));
        self::assertSame(1, $this->tableCount('pa_tenant_audit_event'));
        self::assertSame(1, $this->tableCount('fixture_outbox'));

        $conflict = $this->host->command(
            $create,
            $this->request(
                $tenantId,
                'req_r02_create_0002',
                'POST',
                '/api/v1/fixture/records',
                ['name' => 'Changed payload'],
                [$this->oneTarget($scopeId)],
                '01KPEANUT-R02-CREATE-0001',
            ),
            $createHandler,
            $outbox,
        );
        self::assertSame(409, $conflict->status);
        self::assertSame('IDEMPOTENCY_KEY_REUSED', $conflict->body['code']);

        $list = $this->host->read(
            $this->definition(
                'fixtureRecordsList',
                'GET',
                '/api/v1/fixture/records',
                'read',
                false,
                'query',
                'many_readable',
            ),
            $this->request(
                $tenantId,
                'req_r02_list_0001',
                'GET',
                '/api/v1/fixture/records',
                [],
                [[
                    'target_resource_key' => 'fixture.scope',
                    'target_ids' => [$scopeId],
                    'target_role' => 'primary',
                ]],
            ),
            function (AuthorizedExternalOperation $authorized): ExternalOperationResponse {
                $context = $this->tenantContext($authorized);
                $constraint = $authorized->queryConstraint;
                if ($constraint === null || !property_exists($constraint, 'tenantId')) {
                    throw new RuntimeException('The query constraint is missing its Tenant boundary.');
                }
                self::assertSame($context->tenantId, $constraint->tenantId);
                $statement = $this->database->prepare('SELECT id, name, status, revision FROM fixture_record WHERE tenant_id = :tenant ORDER BY id');
                $statement->execute(['tenant' => $context->tenantId]);
                return new ExternalOperationResponse(200, ['data' => $statement->fetchAll()]);
            },
        );
        self::assertSame(200, $list->status);
        self::assertCount(1, $list->body['data']);

        $detail = $this->host->read(
            $this->definition(
                'fixtureRecordDetail',
                'GET',
                '/api/v1/fixture/records/{record_id}',
                'read',
            ),
            $this->request(
                $tenantId,
                'req_r02_detail_0001',
                'GET',
                "/api/v1/fixture/records/{$recordId}",
                [],
                [$this->oneTarget($scopeId)],
            ),
            function (AuthorizedExternalOperation $authorized) use ($recordId): ExternalOperationResponse {
                $context = $this->tenantContext($authorized);
                $statement = $this->database->prepare('SELECT id, name, status, revision FROM fixture_record WHERE tenant_id = :tenant AND id = :id');
                $statement->execute(['tenant' => $context->tenantId, 'id' => $recordId]);
                $record = $statement->fetch();
                if (!is_array($record)) {
                    throw new ApiException('RESOURCE_NOT_FOUND', 404, 'The requested resource is unavailable.');
                }
                return new ExternalOperationResponse(200, ['data' => $record]);
            },
        );
        self::assertSame('First record', $detail->body['data']['name']);

        $updated = $this->commandRecord(
            'fixtureRecordUpdate',
            'PATCH',
            "/api/v1/fixture/records/{$recordId}",
            'update',
            $tenantId,
            $scopeId,
            '01KPEANUT-R02-UPDATE-0001',
            static function (PDO $pdo, TenantContext $context, string $id): ExternalOperationResult {
                $statement = $pdo->prepare('UPDATE fixture_record SET name = :name, revision = revision + 1 WHERE tenant_id = :tenant AND id = :id');
                $statement->execute(['name' => 'Updated record', 'tenant' => $context->tenantId, 'id' => $id]);
                return new ExternalOperationResult(200, ['data' => ['id' => $id]], 'fixture.record.updated', 'fixture.record.update', ['revision' => 2], 'fixture.record', $id);
            },
            $outbox,
        );
        self::assertSame(200, $updated->status);

        $status = $this->commandRecord(
            'fixtureRecordStatus',
            'POST',
            "/api/v1/fixture/records/{$recordId}/status",
            'status',
            $tenantId,
            $scopeId,
            '01KPEANUT-R02-STATUS-0001',
            static function (PDO $pdo, TenantContext $context, string $id): ExternalOperationResult {
                $statement = $pdo->prepare("UPDATE fixture_record SET status = 'active', revision = revision + 1 WHERE tenant_id = :tenant AND id = :id");
                $statement->execute(['tenant' => $context->tenantId, 'id' => $id]);
                return new ExternalOperationResult(200, ['data' => ['id' => $id, 'status' => 'active']], 'fixture.record.status-changed', 'fixture.record.status', ['revision' => 3], 'fixture.record', $id);
            },
            $outbox,
        );
        self::assertSame('active', $status->body['data']['status']);
        self::assertSame(3, $this->tableCount('pa_tenant_audit_event'));
        self::assertSame(3, $this->tableCount('fixture_outbox'));

        $crossTenantHandlerCalls = 0;
        $crossTenant = $this->host->read(
            $this->definition('fixtureRecordDetail', 'GET', '/api/v1/fixture/records/{record_id}', 'read'),
            $this->request(
                2,
                'req_r02_cross_0001',
                'GET',
                "/api/v1/fixture/records/{$recordId}",
                [],
                [$this->oneTarget($scopeId)],
            ),
            static function () use (&$crossTenantHandlerCalls): ExternalOperationResponse {
                ++$crossTenantHandlerCalls;
                return new ExternalOperationResponse(200, []);
            },
        );
        self::assertSame(404, $crossTenant->status);
        self::assertSame('AUTHZ_DATA_DENIED', $crossTenant->body['code']);
        self::assertSame(0, $crossTenantHandlerCalls);
    }

    public function testDomainOutboxAndCompletionFailuresRollbackAllState(): void
    {
        $tenantId = 1;
        $scopeId = (string) $this->scopeIds[$tenantId];
        $operation = $this->definition(
            'fixtureRecordCreate',
            'POST',
            '/api/v1/fixture/records',
            'create',
            true,
        );
        $baseline = $this->state();

        $domainFailure = $this->host->command(
            $operation,
            $this->request($tenantId, 'req_r02_fail_0001', 'POST', '/api/v1/fixture/records', ['name' => 'Fail domain'], [$this->oneTarget($scopeId)], '01KPEANUT-R02-FAILURE-0001'),
            function (AuthorizedExternalOperation $authorized, ExternalOperationRequest $request, PDO $pdo): ExternalOperationResult {
                $context = $this->tenantContext($authorized);
                $pdo->exec("INSERT INTO fixture_record (tenant_id, scope_id, name, status, revision) VALUES ({$context->tenantId}, {$authorized->targets[0]->targetIds[0]}, 'partial', 'draft', 1)");
                throw new RuntimeException('internal domain failure');
            },
        );
        self::assertSame(500, $domainFailure->status);
        self::assertSame($baseline, $this->state());

        $outboxFailure = $this->host->command(
            $operation,
            $this->request($tenantId, 'req_r02_fail_0002', 'POST', '/api/v1/fixture/records', ['name' => 'Fail outbox'], [$this->oneTarget($scopeId)], '01KPEANUT-R02-FAILURE-0002'),
            $this->insertingHandler(),
            static function (): void {
                throw new RuntimeException('internal outbox failure');
            },
        );
        self::assertSame(500, $outboxFailure->status);
        self::assertSame($baseline, $this->state());

        $completionFailure = $this->host->command(
            $operation,
            $this->request($tenantId, 'req_r02_fail_0003', 'POST', '/api/v1/fixture/records', ['name' => 'Fail completion'], [$this->oneTarget($scopeId)], '01KPEANUT-R02-FAILURE-0003'),
            function (AuthorizedExternalOperation $authorized, ExternalOperationRequest $request, PDO $pdo): ExternalOperationResult {
                $result = ($this->insertingHandler())($authorized, $request, $pdo);
                $pdo->exec("UPDATE pa_tenant_idempotency_record SET status = 'completed' WHERE status = 'processing'");
                return $result;
            },
        );
        self::assertSame(409, $completionFailure->status);
        self::assertSame('IDEMPOTENCY_STATE_CONFLICT', $completionFailure->body['code']);
        self::assertSame($baseline, $this->state());
    }

    public function testExpiredProcessingRecordRemainsNonOwnedAndDoesNotRunDomain(): void
    {
        $tenantId = 1;
        $scopeId = (string) $this->scopeIds[$tenantId];
        $operation = $this->definition(
            'fixtureRecordCreate',
            'POST',
            '/api/v1/fixture/records',
            'create',
            true,
        );
        $request = $this->request(
            $tenantId,
            'req_r02_expired_0001',
            'POST',
            '/api/v1/fixture/records',
            ['name' => 'Expired processing'],
            [$this->oneTarget($scopeId)],
            '01KPEANUT-R02-EXPIRED-0001',
        );
        $repository = new PdoIdempotencyRepository($this->database);
        $comparisonTime = $request->comparisonTime->modify('-2 hours');
        $record = $repository->beginTenant(
            $tenantId,
            $this->memberIds[$tenantId],
            $operation->operationId,
            IdempotencyKey::fromString($request->idempotencyKey),
            $request->requestHash(),
            $comparisonTime->modify('+1 hour'),
            $comparisonTime,
        );
        self::assertTrue($record->acquiredForExecution());
        $handlerCalls = 0;

        $response = $this->host->command(
            $operation,
            $request,
            static function () use (&$handlerCalls): ExternalOperationResult {
                ++$handlerCalls;
                throw new RuntimeException('The expired record must not transfer execution ownership.');
            },
        );

        self::assertSame(409, $response->status);
        self::assertSame('IDEMPOTENCY_REQUEST_PROCESSING', $response->body['code']);
        self::assertSame(0, $handlerCalls);
        self::assertSame(0, $this->tableCount('fixture_record'));
        self::assertSame(1, $this->tableCount('pa_tenant_idempotency_record'));
    }

    private function host(): ExternalOperationHost
    {
        $configuration = new ExternalHostConfiguration(
            new ModuleHostLayout('backend/app/Modules', 'FixtureHost\\App\\Modules', 'frontend/src/modules'),
            ['backend/app/Modules/Fixture/Record'],
            '/api/v1',
            '/api/platform/v1',
            'docs/api/openapi.yaml',
            'backend/route/openapi-generated.php',
            'packages/web/generated/api.d.ts',
            ['fixture-web'],
            'X-Request-ID',
        );
        $registry = new CompiledModuleRegistry([
            ManifestDocument::fromArray('backend/app/Modules/Fixture/Record', ['key' => 'fixture.record']),
        ], ['fixture.scope' => 'fixture.record'], ['fixture_record' => 'fixture.record'], [], 'fixture-revision');
        $tenantRepository = new class implements TenantAuthorizationRepository {
            public function member(int $tenantId, int $memberId): ?array
            {
                return null;
            }
            public function activeRoles(int $tenantId, int $memberId): array
            {
                return [];
            }
            public function revision(int $tenantId, int $memberId): string
            {
                return '1';
            }
            public function permissions(int $tenantId, int $memberId): EffectivePermissionSet
            {
                return new EffectivePermissionSet([
                    'fixture.record.read',
                    'fixture.record.create',
                    'fixture.record.update',
                    'fixture.record.status',
                ]);
            }
        };
        $platformRepository = new class implements PlatformAuthorizationRepository {
            public function revision(int $operatorId): string
            {
                return '1';
            }
            public function permissions(int $operatorId): EffectivePermissionSet
            {
                return new EffectivePermissionSet([]);
            }
        };
        $permissions = new PermissionMiddleware(
            new TenantAuthorizationEvaluator($tenantRepository, new RevisionPermissionCache()),
            new PlatformAuthorizationEvaluator($platformRepository, new RevisionPermissionCache()),
        );
        $dataPermission = new DataPermissionAdapter(
            function (TenantContext $context, string $resourceKey, string $operation, array $targets): object {
                $this->assertTargets($context, $resourceKey, $targets);
                return new class ($context->tenantId) {
                    public function __construct(public int $tenantId) {}
                };
            },
            function (TenantContext $context, string $resourceKey, string $operation, array $targets): void {
                $this->assertTargets($context, $resourceKey, $targets);
            },
        );

        return new ExternalOperationHost(
            $configuration,
            new TrustedContextAdapter($configuration),
            new ModuleAvailabilityAdapter(
                $registry,
                new ModuleGuard(new PdoModuleRuntimeRepository($this->database)),
            ),
            new PermissionAdapter($permissions),
            new TypedTargetAdapter($dataPermission),
            new AtomicOperationAdapter($this->database),
            new ProblemDetailsAdapter(),
        );
    }

    /** @param list<\PeanutAdmin\Kernel\Context\RequestedTargetSet> $targets */
    private function assertTargets(TenantContext $context, string $resourceKey, array $targets): void
    {
        if ($resourceKey !== 'fixture.record' || $targets === []) {
            throw new DataAuthorizationException('AUTHZ_TARGET_TYPE_MISMATCH', 'The requested targets are denied.');
        }
        foreach ($targets as $target) {
            if ($target->targetResourceKey !== 'fixture.scope') {
                throw new DataAuthorizationException('AUTHZ_TARGET_TYPE_MISMATCH', 'The requested targets are denied.');
            }
            foreach ($target->targetIds as $targetId) {
                $statement = $this->database->prepare('SELECT COUNT(*) FROM fixture_scope WHERE tenant_id = :tenant AND id = :id');
                $statement->execute(['tenant' => $context->tenantId, 'id' => $targetId]);
                if ((int) $statement->fetchColumn() !== 1) {
                    throw new DataAuthorizationException('AUTHZ_TARGET_NOT_FOUND', 'The requested targets are denied.');
                }
            }
        }
    }

    private function definition(
        string $operationId,
        string $method,
        string $path,
        string $permission,
        bool $command = false,
        string $dataMode = 'targets',
        string $cardinality = 'one_required',
    ): ExternalOperationDefinition {
        return new ExternalOperationDefinition(
            $operationId,
            $method,
            $path,
            'tenant',
            'fixture.record',
            new PermissionRequirement('tenant', ["fixture.record.{$permission}"]),
            'fixture.record',
            $dataMode,
            $cardinality,
            $command,
            $command,
        );
    }

    /**
     * @param array<string, mixed> $body
     * @param list<array<string, mixed>> $targets
     */
    private function request(
        int $tenantId,
        string $requestId,
        string $method,
        string $path,
        array $body = [],
        array $targets = [],
        ?string $idempotencyKey = null,
    ): ExternalOperationRequest {
        $now = new DateTimeImmutable('2026-07-19T00:00:00Z');
        return new ExternalOperationRequest(
            RequestId::fromHeader($requestId),
            $this->context($tenantId, $requestId),
            $method,
            $path,
            $body,
            $targets,
            $idempotencyKey,
            $now,
            $now->modify('+1 hour'),
        );
    }

    private function context(int $tenantId, string $requestId): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $tenantId,
            "session_fixture_{$tenantId}",
            $tenantId,
            $this->accountIds[$tenantId],
            $this->memberIds[$tenantId],
            'fixture-web',
            new DateTimeImmutable('2026-07-19T00:00:00Z'),
            1,
        ), $requestId);
    }

    private function tenantContext(AuthorizedExternalOperation $authorized): TenantContext
    {
        if (!$authorized->context instanceof TenantContext) {
            throw new RuntimeException('A Tenant context is required.');
        }
        return $authorized->context;
    }

    /** @return array<string, mixed> */
    private function oneTarget(string $scopeId): array
    {
        return [
            'target_resource_key' => 'fixture.scope',
            'target_id' => $scopeId,
            'target_role' => 'primary',
        ];
    }

    /**
     * @param callable(PDO, TenantContext, string): ExternalOperationResult $domain
     * @param callable(PDO, ExternalOperationResult): void $outbox
     */
    private function commandRecord(
        string $operationId,
        string $method,
        string $path,
        string $permission,
        int $tenantId,
        string $scopeId,
        string $idempotencyKey,
        callable $domain,
        callable $outbox,
    ): ExternalOperationResponse {
        $recordId = explode('/', trim($path, '/'))[4];
        return $this->host->command(
            $this->definition($operationId, $method, str_ends_with($path, '/status')
                ? '/api/v1/fixture/records/{record_id}/status'
                : '/api/v1/fixture/records/{record_id}', $permission, true),
            $this->request($tenantId, 'req_' . strtolower($operationId), $method, $path, [], [$this->oneTarget($scopeId)], $idempotencyKey),
            function (AuthorizedExternalOperation $authorized, ExternalOperationRequest $request, PDO $pdo) use ($domain, $recordId): ExternalOperationResult {
                return $domain($pdo, $this->tenantContext($authorized), $recordId);
            },
            $outbox,
        );
    }

    /** @return callable(AuthorizedExternalOperation, ExternalOperationRequest, PDO): ExternalOperationResult */
    private function insertingHandler(): callable
    {
        return function (AuthorizedExternalOperation $authorized, ExternalOperationRequest $request, PDO $pdo): ExternalOperationResult {
            $context = $this->tenantContext($authorized);
            $statement = $pdo->prepare("INSERT INTO fixture_record (tenant_id, scope_id, name, status, revision) VALUES (:tenant, :scope, :name, 'draft', 1)");
            $statement->execute([
                'tenant' => $context->tenantId,
                'scope' => (int) $authorized->targets[0]->targetIds[0],
                'name' => (string) $request->body['name'],
            ]);
            $id = (string) $pdo->lastInsertId();
            return new ExternalOperationResult(201, ['data' => ['id' => $id]], 'fixture.record.created', 'fixture.record.create', ['revision' => 1], 'fixture.record', $id);
        };
    }

    private function createFixtureSchema(): void
    {
        $this->database->exec(<<<'SQL'
CREATE TABLE fixture_scope (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(80) NOT NULL,
    UNIQUE KEY uk_fixture_scope_tenant (tenant_id, id)
) ENGINE=InnoDB;
CREATE TABLE fixture_record (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    scope_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    status VARCHAR(24) NOT NULL,
    revision BIGINT UNSIGNED NOT NULL,
    KEY idx_fixture_record_tenant (tenant_id, id)
) ENGINE=InnoDB;
CREATE TABLE fixture_outbox (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_key VARCHAR(96) NOT NULL,
    resource_id VARCHAR(128) NULL
) ENGINE=InnoDB
SQL);
    }

    private function seedFixture(): void
    {
        $now = '2026-07-19 00:00:00.000';
        $this->database->exec("INSERT INTO pa_module_installation (module_key, installed_version, manifest_schema_version, manifest_digest, status, revision, installed_at, activated_at, created_at, updated_at) VALUES ('fixture.record', '1.0.0', 1, REPEAT('a', 64), 'active', 1, '{$now}', '{$now}', '{$now}', '{$now}')");
        foreach ([1, 2] as $tenantSequence) {
            $accountId = $this->insert('pa_account', [
                'display_name' => "Fixture {$tenantSequence}",
                'status' => 'active',
                'security_revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $tenantId = $this->insert('pa_tenant', [
                'code' => "fixture-{$tenantSequence}",
                'name' => "Fixture {$tenantSequence}",
                'display_name' => "Fixture {$tenantSequence}",
                'status' => 'active',
                'locale' => 'zh-CN',
                'timezone' => 'Asia/Shanghai',
                'security_revision' => 1,
                'authorization_revision' => 1,
                'revision' => 1,
                'activated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            self::assertSame($tenantSequence, $tenantId);
            $memberId = $this->insert('pa_tenant_member', [
                'tenant_id' => $tenantId,
                'account_id' => $accountId,
                'member_type' => 'internal',
                'status' => 'active',
                'security_revision' => 1,
                'authorization_revision' => 1,
                'joined_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->insert('pa_tenant_module', [
                'tenant_id' => $tenantId,
                'module_key' => 'fixture.record',
                'status' => 'enabled',
                'source' => 'manual',
                'config_revision' => 1,
                'authorization_revision' => 1,
                'effective_at' => $now,
                'enabled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $scopeId = $this->insert('fixture_scope', [
                'tenant_id' => $tenantId,
                'name' => "Scope {$tenantSequence}",
            ]);
            $this->accountIds[$tenantId] = $accountId;
            $this->memberIds[$tenantId] = $memberId;
            $this->scopeIds[$tenantId] = $scopeId;
        }
    }

    /** @param array<string, int|string|null> $values */
    private function insert(string $table, array $values): int
    {
        $columns = array_keys($values);
        $statement = $this->database->prepare(sprintf(
            'INSERT INTO `%s` (`%s`) VALUES (:%s)',
            $table,
            implode('`, `', $columns),
            implode(', :', $columns),
        ));
        $statement->execute($values);
        return (int) $this->database->lastInsertId();
    }

    /** @return list<int> */
    private function state(): array
    {
        return [
            $this->tableCount('fixture_record'),
            $this->tableCount('pa_tenant_audit_event'),
            $this->tableCount('fixture_outbox'),
            $this->tableCount('pa_tenant_idempotency_record'),
        ];
    }

    private function tableCount(string $table): int
    {
        $statement = $this->database->query("SELECT COUNT(*) FROM `{$table}`");
        if ($statement === false) {
            throw new RuntimeException('Fixture count query failed.');
        }
        return (int) $statement->fetchColumn();
    }

    private function connect(?string $database = null): PDO
    {
        $dsn = sprintf(
            'mysql:host=127.0.0.1;port=%d%s;charset=utf8mb4',
            (int) (getenv('MYSQL_PORT') ?: 33382),
            $database === null ? '' : ";dbname={$database}",
        );
        return new PDO($dsn, 'root', getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
