<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Tests\Integration;

use DateTimeImmutable;
use PDO;
use PeanutAdmin\App\Tests\Support\ThinkPhpTestConnection;
use PeanutAdmin\FileMedia\Application\FileMediaException;
use PeanutAdmin\FileMedia\Application\FileService;
use PeanutAdmin\FileMedia\Application\UploadPolicy;
use PeanutAdmin\FileMedia\Database\Schema;
use PeanutAdmin\FileMedia\Storage\StorageProvider;
use PeanutAdmin\FileMedia\Storage\StoredObject;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Audit\AuditService;
use PeanutAdmin\Kernel\Module\ModuleAvailabilityService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class FileServiceTest extends TestCase
{
    private const DATABASE = 'peanut_admin_c02_file_media_test';

    private PDO $admin;
    private PDO $pdo;
    private MemoryStorageProvider $storage;
    private string $upload;

    protected function setUp(): void
    {
        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through the focused File/Media MySQL gate.');
        }
        if (getenv('DB_HOST') !== '127.0.0.1') {
            throw new RuntimeException('DB_HOST must be 127.0.0.1.');
        }
        $port = (int) (getenv('DB_PORT') ?: 0);
        if ($port < 1024 || $port > 65535 || $port !== (int) getenv('MYSQL_PORT')) {
            throw new RuntimeException('File/Media MYSQL_PORT and DB_PORT must match an explicit local port.');
        }
        $password = getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev';
        $this->admin = new PDO(
            "mysql:host=127.0.0.1;port={$port};charset=utf8mb4",
            'root',
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->admin->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        $this->admin->exec('CREATE DATABASE `' . self::DATABASE . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');
        $this->pdo = new PDO(
            "mysql:host=127.0.0.1;port={$port};dbname=" . self::DATABASE . ';charset=utf8mb4',
            'root',
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false],
        );
        $this->pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (id)
) ENGINE=InnoDB
SQL);
        $this->pdo->exec(<<<'SQL'
CREATE TABLE pa_tenant_member (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id BIGINT UNSIGNED NOT NULL,
  account_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(16) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uk_tenant_member_pair (tenant_id, id),
  CONSTRAINT fk_file_test_member_tenant FOREIGN KEY (tenant_id) REFERENCES pa_tenant(id) ON DELETE RESTRICT
) ENGINE=InnoDB
SQL);
        $this->pdo->exec(Schema::createSql('pa_file_object'));
        $this->pdo->exec(<<<'SQL'
CREATE TABLE pa_module_installation (
  module_key VARCHAR(160) NOT NULL PRIMARY KEY,
  status VARCHAR(32) NOT NULL
) ENGINE=InnoDB;
CREATE TABLE pa_tenant_module (
  tenant_id BIGINT UNSIGNED NOT NULL,
  module_key VARCHAR(160) NOT NULL,
  status VARCHAR(32) NOT NULL,
  effective_at DATETIME(3) NULL,
  expires_at DATETIME(3) NULL,
  PRIMARY KEY (tenant_id, module_key)
) ENGINE=InnoDB;
CREATE TABLE pa_tenant_audit_event (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  tenant_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(160) NOT NULL,
  action VARCHAR(160) NOT NULL,
  outcome VARCHAR(32) NOT NULL,
  actor_tenant_id BIGINT UNSIGNED NULL,
  actor_tenant_member_id BIGINT UNSIGNED NULL,
  actor_account_id BIGINT UNSIGNED NULL,
  actor_type VARCHAR(32) NOT NULL,
  target_resource_type VARCHAR(160) NULL,
  target_resource_id VARCHAR(160) NULL,
  boundary_target_type VARCHAR(160) NULL,
  boundary_target_id VARCHAR(160) NULL,
  target_count INT NOT NULL,
  target_set_digest VARCHAR(128) NULL,
  request_id VARCHAR(160) NOT NULL,
  metadata_json JSON NULL,
  occurred_at DATETIME(3) NOT NULL
) ENGINE=InnoDB;
INSERT INTO pa_module_installation (module_key, status) VALUES ('peanut.file-media', 'active');
SQL);
        ThinkPhpTestConnection::fromPdo($this->pdo);
        $this->storage = new MemoryStorageProvider();
        $this->upload = tempnam(sys_get_temp_dir(), 'peanut-file-upload-') ?: throw new RuntimeException('Cannot create upload fixture.');
        file_put_contents($this->upload, "tenant private bytes\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->upload ?? '');
        if (isset($this->admin)) {
            $this->admin->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        }
    }

    public function testTenantPrivateLifecycleAndTerminalArchive(): void
    {
        $tenantA = $this->tenant(101);
        $tenantB = $this->tenant(202);
        $service = $this->service();

        $file = $service->upload($tenantA, $this->upload, '../private report.txt');
        self::assertSame('ready', $file->status);
        self::assertSame(hash_file('sha256', $this->upload), $file->sha256);
        self::assertCount(1, $service->list($tenantA, 'ready', 1, 20)['items']);
        self::assertCount(0, $service->list($tenantB, 'ready', 1, 20)['items']);
        $this->expectFileError('FILE_NOT_FOUND', fn() => $service->detail($tenantB, $file->fileKey));

        $stream = $service->content($tenantA, $file->fileKey);
        self::assertSame("tenant private bytes\n", stream_get_contents($stream));
        fclose($stream);

        $archived = $service->archive($tenantA, $file->fileKey, '"rev-1"');
        self::assertSame('archived', $archived->status);
        self::assertSame(2, $archived->revision);
        self::assertSame($archived->toArray(), $service->archive($tenantA, $file->fileKey, '"rev-2"')->toArray());
        self::assertCount(0, $service->list($tenantA, 'ready', 1, 20)['items']);
        self::assertCount(1, $service->list($tenantA, 'archived', 1, 20)['items']);
        $this->expectFileError('FILE_NOT_FOUND', fn() => $service->content($tenantA, $file->fileKey));
        $this->expectFileError('REVISION_CONFLICT', fn() => $service->archive($tenantA, $file->fileKey, '"rev-1"'));
    }

    public function testDatabaseFailureCompensatesStoredObject(): void
    {
        $tenant = $this->tenant(303);
        $this->pdo->exec('DROP TABLE pa_file_object');

        $this->expectFileError('INTERNAL_ERROR', fn() => $this->service()->upload($tenant, $this->upload, 'report.txt'));
        self::assertSame([], $this->storage->objects);
        self::assertSame(1, $this->storage->removeCount);
    }

    public function testMigrationSqlIsIdempotentAtTheRunnerBoundary(): void
    {
        self::assertSame(['pa_file_object'], Schema::tableNames());
        self::assertStringContainsString('uk_file_object_storage', Schema::createSql('pa_file_object'));
        self::assertStringContainsString('DROP TABLE IF EXISTS', Schema::dropSql('pa_file_object'));
    }

    private function service(): FileService
    {
        return new FileService(
            $this->storage,
            new UploadPolicy(['text/plain']),
            new AuditService(),
            new ModuleAvailabilityService(),
        );
    }

    private function tenant(int $accountId): TenantContext
    {
        $this->pdo->exec('INSERT INTO pa_tenant VALUES ()');
        $tenantId = (int) $this->pdo->lastInsertId();
        $statement = $this->pdo->prepare(
            "INSERT INTO pa_tenant_member (tenant_id, account_id, status) VALUES (?, ?, 'active')",
        );
        $statement->execute([$tenantId, $accountId]);
        $memberId = (int) $this->pdo->lastInsertId();
        $statement = $this->pdo->prepare(
            "INSERT INTO pa_tenant_module (tenant_id, module_key, status) VALUES (?, 'peanut.file-media', 'enabled')",
        );
        $statement->execute([$tenantId]);

        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            $accountId,
            '01J00000000000000000000000',
            $tenantId,
            $accountId,
            $memberId,
            'admin-web',
            new DateTimeImmutable('2030-01-01T00:00:00.000Z'),
            1,
        ), "req_file_media_{$tenantId}");
    }

    private function expectFileError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected {$code}");
        } catch (FileMediaException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}

final class MemoryStorageProvider implements StorageProvider
{
    /** @var array<string, string> */
    public array $objects = [];
    public int $removeCount = 0;

    public function key(): string
    {
        return 'memory-test';
    }

    public function store(int $tenantId, string $fileKey, string $sourcePath): StoredObject
    {
        $key = "tenant-{$tenantId}/{$fileKey}";
        $contents = file_get_contents($sourcePath);
        if (!is_string($contents)) {
            throw FileMediaException::storageUnavailable();
        }
        $this->objects[$key] = $contents;

        return new StoredObject($this->key(), $key);
    }

    public function open(string $storageKey)
    {
        if (!array_key_exists($storageKey, $this->objects)) {
            throw FileMediaException::storageUnavailable();
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw FileMediaException::storageUnavailable();
        }
        fwrite($stream, $this->objects[$storageKey]);
        rewind($stream);

        return $stream;
    }

    public function remove(string $storageKey): void
    {
        unset($this->objects[$storageKey]);
        ++$this->removeCount;
    }
}
