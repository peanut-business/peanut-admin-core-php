<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Tests\Integration\Support;

use DateTimeImmutable;
use PDO;
use PDOException;
use PeanutAdmin\Kernel\Api\RequestId;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\DataPermissionAdapter;
use PeanutAdmin\Kernel\Authorization\EffectivePermissionSet;
use PeanutAdmin\Kernel\Authorization\RevisionPermissionCache;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationEvaluator;
use PeanutAdmin\Kernel\Authorization\TenantAuthorizationRepository;
use PeanutAdmin\Kernel\Host\AtomicOperationAdapter;
use PeanutAdmin\Kernel\Host\AuthorizedExternalOperation;
use PeanutAdmin\Kernel\Host\ExternalHostConfiguration;
use PeanutAdmin\Kernel\Host\ExternalOperationDefinition;
use PeanutAdmin\Kernel\Host\ExternalOperationHost;
use PeanutAdmin\Kernel\Host\ExternalOperationRequest;
use PeanutAdmin\Kernel\Host\ModuleAvailabilityAdapter;
use PeanutAdmin\Kernel\Host\PermissionAdapter;
use PeanutAdmin\Kernel\Host\ProblemDetailsAdapter;
use PeanutAdmin\Kernel\Host\TrustedContextAdapter;
use PeanutAdmin\Kernel\Host\TypedTargetAdapter;
use PeanutAdmin\Kernel\Http\PermissionMiddleware;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleGuard;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;
use PeanutAdmin\Kernel\Module\ModuleInstallationRecord;
use PeanutAdmin\Kernel\Module\ModuleRuntimeRepository;
use PeanutAdmin\Kernel\Module\TenantModuleRecord;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationEvaluator;
use PeanutAdmin\Kernel\Platform\Authorization\PlatformAuthorizationRepository;
use PeanutAdmin\Settings\Definition\SettingDefinition;
use PeanutAdmin\Settings\Definition\SettingDefinitionLoader;
use PeanutAdmin\Settings\Definition\SettingDefinitionRegistry;
use PeanutAdmin\Settings\Persistence\PdoSettingRepository;
use PeanutAdmin\Settings\Tests\Integration\Schema\SettingsMigrationRunner;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

require_once dirname(__DIR__) . '/Schema/SettingsMigrationRunner.php';

abstract class SettingsDatabaseTestCase extends TestCase
{
    protected const DATABASE = 'peanut_admin_p1_b03_settings_test';
    protected const NOW = '2026-07-19 08:00:00.000';

    protected PDO $admin;
    protected PDO $database;
    protected SettingsMigrationRunner $runner;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through scripts/test-integration.');
        }
        $this->requiredPort('MYSQL_PORT');
        $this->requiredPort('DB_PORT');

        $this->admin = $this->connect();
        $this->admin->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        $this->admin->exec(
            'CREATE DATABASE `' . self::DATABASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
        );
        $this->database = $this->connect(self::DATABASE);
        $this->createParentTables();
        $this->runner = new SettingsMigrationRunner($this->database);
        $this->runner->migrate();
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        if (isset($this->admin)) {
            $this->admin->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        }
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $definitions
     * @param list<array{module_key: string, resource_key: string, operation: string, target_cardinality: string}> $targets
     */
    protected function registry(
        array $definitions,
        string $moduleKey = 'example.module',
        array $targets = [],
    ): SettingDefinitionRegistry {
        $file = tempnam(sys_get_temp_dir(), 'peanut-settings-integration-');
        if (!is_string($file)) {
            throw new RuntimeException('Cannot allocate settings fixture file.');
        }
        file_put_contents($file, json_encode($definitions, JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $file;
        $registry = new SettingDefinitionRegistry();
        $registry->registerModule(
            $moduleKey,
            (new SettingDefinitionLoader())->load($moduleKey, $file, $targets),
        );

        return $registry;
    }

    /** @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    protected function definition(array $override = []): array
    {
        return array_merge([
            'key' => 'display-mode',
            'name' => 'Display mode',
            'description' => 'Controls a generic display mode.',
            'schema' => [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'string',
                'enum' => ['compact', 'comfortable'],
            ],
            'required' => false,
            'secret' => false,
            'allowed_scopes' => ['deployment', 'tenant', 'target'],
            'target_resource_key' => 'example.project',
            'target_operation' => 'updateProjectSetting',
            'default' => 'comfortable',
        ], $override);
    }

    protected function synchronize(SettingDefinitionRegistry $registry): PdoSettingRepository
    {
        $repository = new PdoSettingRepository($this->database);
        $repository->synchronize($registry, new \DateTimeImmutable(self::NOW . ' UTC'));

        return $repository;
    }

    /** @return array{tenant_id: int, member_id: int} */
    protected function tenant(string $code): array
    {
        $statement = $this->database->prepare(
            'INSERT INTO pa_tenant (code) VALUES (:code)',
        );
        $statement->execute(['code' => $code]);
        $tenantId = (int) $this->database->lastInsertId();
        $statement = $this->database->prepare(
            'INSERT INTO pa_tenant_member (tenant_id) VALUES (:tenant_id)',
        );
        $statement->execute(['tenant_id' => $tenantId]);

        return ['tenant_id' => $tenantId, 'member_id' => (int) $this->database->lastInsertId()];
    }

    protected function operator(): int
    {
        $this->database->exec('INSERT INTO pa_platform_operator () VALUES ()');

        return (int) $this->database->lastInsertId();
    }

    protected function additionalDatabaseConnection(): PDO
    {
        return $this->connect(self::DATABASE);
    }

    /** @param array{tenant_id: int, member_id: int} $tenant
     * @param non-empty-list<string> $targetIds
     */
    protected function authorizeTarget(
        array $tenant,
        string $targetResourceKey,
        array $targetIds,
        ExternalOperationDefinition $operation,
    ): AuthorizedExternalOperation {
        $requestId = 'req_settings_' . substr(hash('sha256', implode(':', [
            $operation->moduleKey,
            $operation->operationId,
            $targetResourceKey,
            ...$targetIds,
        ])), 0, 24);
        $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            '01J00000000000000000000000',
            $tenant['tenant_id'],
            1,
            $tenant['member_id'],
            'admin-web',
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            1,
        ), $requestId);
        $typedTargets = array_map(
            static fn(string $targetId): array => [
                'target_resource_key' => $targetResourceKey,
                'target_id' => $targetId,
            ],
            $targetIds,
        );
        $actualPath = preg_replace(
            '/\{[a-z][a-z0-9_]*\}/',
            rawurlencode($targetIds[0]),
            $operation->path,
        );
        if (!is_string($actualPath)) {
            throw new RuntimeException('Cannot build the settings Host request path.');
        }
        $request = new ExternalOperationRequest(
            RequestId::fromHeader($requestId),
            $context,
            $operation->method,
            $actualPath,
            [],
            $typedTargets,
            '01KPEANUT-B03-TARGET-0001',
            new DateTimeImmutable('2026-07-19T08:00:00Z'),
            new DateTimeImmutable('2026-07-19T09:00:00Z'),
        );
        $targetChecks = 0;
        $dataPermission = new DataPermissionAdapter(
            static fn(): never => throw new RuntimeException('Settings write authorization must not query targets.'),
            static function (
                TenantContext $authorizedContext,
                string $resourceKey,
                string $operationId,
                array $requestedTargets,
            ) use (
                $tenant,
                $operation,
                $targetResourceKey,
                $targetIds,
                &$targetChecks,
            ): void {
                ++$targetChecks;
                self::assertSame($tenant['tenant_id'], $authorizedContext->tenantId);
                self::assertSame($operation->resourceKey, $resourceKey);
                self::assertSame($operation->operationId, $operationId);
                self::assertSame([[
                    'target_resource_key' => $targetResourceKey,
                    'target_role' => 'primary',
                    'target_ids' => $targetIds,
                ]], array_map(
                    static fn($target): array => $target->toArray(),
                    $requestedTargets,
                ));
            },
        );
        $host = $this->authorizationHost($operation, $dataPermission);
        $authorize = new ReflectionMethod(ExternalOperationHost::class, 'authorize');
        $authorize->setAccessible(true);
        $authorized = $authorize->invoke($host, $operation, $request);

        self::assertSame(1, $targetChecks);
        if (!$authorized instanceof AuthorizedExternalOperation) {
            throw new RuntimeException('ExternalOperationHost did not issue a settings authorization.');
        }

        return $authorized;
    }

    protected function scalar(string $sql): int|string|false
    {
        $statement = $this->database->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Database query failed.');
        }

        $value = $statement->fetchColumn();
        if ($value === null) {
            throw new RuntimeException('Database query returned a null scalar.');
        }

        return $value;
    }

    protected function assertDatabaseRejects(callable $operation): void
    {
        try {
            $operation();
        } catch (PDOException $exception) {
            self::assertNotSame('00000', $exception->getCode());

            return;
        }
        self::fail('Expected MySQL to reject the invalid row.');
    }

    private function authorizationHost(
        ExternalOperationDefinition $operation,
        DataPermissionAdapter $dataPermission,
    ): ExternalOperationHost {
        $configuration = new ExternalHostConfiguration(
            new ModuleHostLayout('backend/app/Modules', 'FixtureHost\\App\\Modules', 'frontend/src/modules'),
            ['backend/app/Modules/Example/Module', 'backend/app/Modules/Other/Module'],
            '/api/example/v1',
            '/api/platform/v1',
            'docs/api/openapi.yaml',
            'backend/route/openapi-generated.php',
            'packages/web/generated/api.d.ts',
            ['admin-web'],
            'X-Request-ID',
        );
        $registry = new CompiledModuleRegistry([
            ManifestDocument::fromArray('backend/app/Modules/Example/Module', ['key' => 'example.module']),
            ManifestDocument::fromArray('backend/app/Modules/Other/Module', ['key' => 'other.module']),
        ], [], [], [], 'settings-host-revision');
        $moduleRepository = new class implements ModuleRuntimeRepository {
            public function installation(string $moduleKey): ModuleInstallationRecord
            {
                return new ModuleInstallationRecord($moduleKey, '1.0.0', 'active', 1, 'digest');
            }

            public function tenantModule(int $tenantId, string $moduleKey): TenantModuleRecord
            {
                return new TenantModuleRecord($tenantId, $moduleKey, 'enabled', null, null, 1);
            }

            public function enabledDependents(int $tenantId, string $moduleKey): array
            {
                return [];
            }
        };
        $tenantRepository = new class ($operation->permission->permissionKeys) implements TenantAuthorizationRepository {
            /** @param list<string> $permissions */
            public function __construct(private array $permissions) {}

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
                return new EffectivePermissionSet($this->permissions);
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

        return new ExternalOperationHost(
            $configuration,
            new TrustedContextAdapter($configuration),
            new ModuleAvailabilityAdapter($registry, new ModuleGuard($moduleRepository)),
            new PermissionAdapter($permissions),
            new TypedTargetAdapter($dataPermission),
            new AtomicOperationAdapter($this->database),
            new ProblemDetailsAdapter(),
        );
    }

    private function createParentTables(): void
    {
        $this->database->exec(<<<'SQL'
CREATE TABLE pa_tenant (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(64) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_tenant_code (code)
) ENGINE=InnoDB
SQL);
        $this->database->exec(<<<'SQL'
CREATE TABLE pa_tenant_member (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_tenant_member_tenant_id (tenant_id, id),
  CONSTRAINT fk_test_member_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT
) ENGINE=InnoDB
SQL);
        $this->database->exec(<<<'SQL'
CREATE TABLE pa_platform_operator (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (id)
) ENGINE=InnoDB
SQL);
    }

    private function connect(?string $database = null): PDO
    {
        $dsn = sprintf(
            'mysql:host=127.0.0.1;port=%d%s;charset=utf8mb4',
            $this->requiredPort('DB_PORT'),
            $database === null ? '' : ';dbname=' . $database,
        );

        return new PDO(
            $dsn,
            'root',
            getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }

    protected function requiredPort(string $name): int
    {
        $value = getenv($name);
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new \RuntimeException("Missing required environment variable: {$name}.");
        }
        $port = (int) $value;
        if ($port < 1 || $port > 65535) {
            throw new \RuntimeException("Invalid port in environment variable: {$name}.");
        }

        return $port;
    }
}
