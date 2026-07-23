<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Tests\Integration\Support;

use DateTimeImmutable;
use PDO;
use PDOException;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\ReferenceCodes\Application\ReferenceCodeAdminService;
use PeanutAdmin\ReferenceCodes\Application\ReferenceCodeException;
use PeanutAdmin\ReferenceCodes\Application\ReferenceCodeQuery;
use PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetDefinition;
use PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetLoader;
use PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetRegistry;
use PeanutAdmin\ReferenceCodes\Persistence\PdoReferenceCodeRepository;
use PeanutAdmin\ReferenceCodes\Tests\Integration\Schema\ReferenceCodesMigrationRunner;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__) . '/Schema/ReferenceCodesMigrationRunner.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'PeanutAdmin\\ReferenceCodes\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = dirname(__DIR__, 3) . '/src/' . $relative . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

abstract class ReferenceCodesDatabaseTestCase extends TestCase
{
    protected const DATABASE = 'peanut_admin_p1_b04_reference_codes_test';
    protected const NOW = '2026-07-20T08:00:00.000Z';

    protected PDO $admin;
    protected PDO $database;
    protected ReferenceCodesMigrationRunner $runner;

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Integrator runs the MySQL-focused reference-code suites.');
        }
        if (getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('DB_HOST must be 127.0.0.1.');
        }
        $mysqlPort = $this->requiredPort('MYSQL_PORT');
        $dbPort = $this->requiredPort('DB_PORT');
        if ($mysqlPort !== $dbPort) {
            throw new RuntimeException('Reference Codes MYSQL_PORT and DB_PORT must match.');
        }

        $this->admin = $this->connect();
        $this->admin->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        $this->admin->exec(
            'CREATE DATABASE `' . self::DATABASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
        );
        $this->database = $this->connect(self::DATABASE);
        $this->createParentTables();
        $this->runner = new ReferenceCodesMigrationRunner($this->database);
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

    protected function definition(
        string $moduleKey = 'example.reference-owner',
        string $setKey = 'generic-codes',
        string $name = 'Generic codes',
        string $description = 'Neutral reusable reference values.',
    ): ReferenceCodeSetDefinition {
        $file = tempnam(sys_get_temp_dir(), 'peanut-reference-codes-');
        if (!is_string($file)) {
            throw new RuntimeException('Cannot allocate a reference-code fixture.');
        }
        file_put_contents($file, json_encode([[
            'key' => $setKey,
            'name' => $name,
            'description' => $description,
        ]], JSON_THROW_ON_ERROR));
        $this->temporaryFiles[] = $file;

        return (new ReferenceCodeSetLoader())->load($moduleKey, $file)[0];
    }

    protected function registry(ReferenceCodeSetDefinition ...$definitions): ReferenceCodeSetRegistry
    {
        $registry = new ReferenceCodeSetRegistry();
        $grouped = [];
        foreach ($definitions as $definition) {
            $grouped[$definition->moduleKey][] = $definition;
        }
        foreach ($grouped as $moduleKey => $owned) {
            $registry->registerModule($moduleKey, $owned);
        }

        return $registry;
    }

    protected function repository(ReferenceCodeSetDefinition $definition): PdoReferenceCodeRepository
    {
        $repository = new PdoReferenceCodeRepository($this->database);
        $repository->synchronize($this->registry($definition), new DateTimeImmutable(self::NOW));

        return $repository;
    }

    protected function adminService(PdoReferenceCodeRepository $repository): ReferenceCodeAdminService
    {
        return new ReferenceCodeAdminService($repository);
    }

    protected function query(PdoReferenceCodeRepository $repository): ReferenceCodeQuery
    {
        return new ReferenceCodeQuery($repository);
    }

    /** @return array{tenant_id: int, member_id: int, context: TenantContext} */
    protected function tenant(string $code): array
    {
        $statement = $this->database->prepare('INSERT INTO pa_tenant (code) VALUES (:code)');
        $statement->execute(['code' => $code]);
        $tenantId = (int) $this->database->lastInsertId();
        $statement = $this->database->prepare(
            'INSERT INTO pa_tenant_member (tenant_id) VALUES (:tenant_id)',
        );
        $statement->execute(['tenant_id' => $tenantId]);
        $memberId = (int) $this->database->lastInsertId();

        return [
            'tenant_id' => $tenantId,
            'member_id' => $memberId,
            'context' => $this->context($tenantId, $memberId),
        ];
    }

    protected function context(int $tenantId, int $memberId): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            '01J00000000000000000000000',
            $tenantId,
            1,
            $memberId,
            'admin-web',
            new DateTimeImmutable(self::NOW),
            1,
        ), 'req_reference_codes_' . $tenantId . '_' . $memberId);
    }

    /** @param array<string, mixed> $override */
    protected function create(
        ReferenceCodeAdminService $service,
        ReferenceCodeSetDefinition $definition,
        TenantContext $context,
        string $code = 'sample-code',
        array $override = [],
    ): \PeanutAdmin\ReferenceCodes\Application\EffectiveReferenceCode {
        $input = array_merge([
            'label' => 'Sample label',
            'metadata' => [],
            'status' => 'active',
            'sort_order' => 0,
            'effective_at' => new DateTimeImmutable('2020-01-01T00:00:00.000Z'),
            'expires_at' => null,
        ], $override);

        return $service->create(
            $definition,
            $context,
            $code,
            $input['label'],
            $input['metadata'],
            $input['status'],
            $input['sort_order'],
            $input['effective_at'],
            $input['expires_at'],
            '*',
        );
    }

    protected function expectReferenceCodeError(string $code, int $status, callable $operation): void
    {
        try {
            $operation();
        } catch (ReferenceCodeException $exception) {
            self::assertSame($code, $exception->errorCode);
            self::assertSame($status, $exception->httpStatus);

            return;
        }
        self::fail("Expected reference-code error {$code}.");
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
  CONSTRAINT fk_reference_test_member_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant (id) ON DELETE RESTRICT
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

    private function requiredPort(string $name): int
    {
        $value = getenv($name);
        if (!is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
            throw new RuntimeException("Missing required environment variable: {$name}.");
        }

        $port = (int) $value;
        if ($port < 1 || $port > 65535) {
            throw new RuntimeException("Invalid port in environment variable: {$name}.");
        }

        return $port;
    }
}
