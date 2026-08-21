<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Schema;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/KernelMigrationRunner.php';

abstract class DatabaseTestCase extends TestCase
{
    protected const DATABASE = 'peanut_admin_kernel_test';

    protected PDO $admin;
    protected PDO $database;
    protected KernelMigrationRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('PEANUT_INTEGRATION') !== '1') {
            self::markTestSkipped('Run through scripts/test-integration.');
        }

        $this->admin = $this->connect();
        $this->admin->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        $this->admin->exec(
            'CREATE DATABASE `' . self::DATABASE
            . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci',
        );

        $this->database = $this->connect(self::DATABASE);
        $this->runner = new KernelMigrationRunner(
            self::DATABASE,
            '127.0.0.1',
            (int) (getenv('MYSQL_PORT') ?: 3306),
            'root',
            getenv('MYSQL_ROOT_PASSWORD') ?: 'peanut_admin_root_dev',
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            $this->admin->exec('DROP DATABASE IF EXISTS `' . self::DATABASE . '`');
        }

        parent::tearDown();
    }

    /** @param array<string, int|string|null> $values */
    protected function insert(string $table, array $values): int
    {
        $columns = array_keys($values);
        $quotedColumns = array_map(
            static fn(string $column): string => "`{$column}`",
            $columns,
        );
        $parameters = array_map(
            static fn(string $column): string => ":{$column}",
            $columns,
        );

        $statement = $this->database->prepare(sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $table,
            implode(', ', $quotedColumns),
            implode(', ', $parameters),
        ));
        $statement->execute($values);

        return (int) $this->database->lastInsertId();
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

    protected function query(string $sql): PDOStatement
    {
        $statement = $this->database->query($sql);
        if ($statement === false) {
            throw new RuntimeException('MySQL query did not return a statement.');
        }

        return $statement;
    }

    private function connect(?string $database = null): PDO
    {
        $dsn = sprintf(
            'mysql:host=127.0.0.1;port=%d%s;charset=utf8mb4',
            (int) (getenv('MYSQL_PORT') ?: 3306),
            $database === null ? '' : ";dbname={$database}",
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
}
