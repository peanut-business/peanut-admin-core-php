<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Persistence;

use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;
use RuntimeException;
use think\facade\Db;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class ThinkPhpTransactionTest extends DatabaseTestCase
{
    public function testCaughtNestedFailureRollsBackOnlyItsSavepoint(): void
    {
        $this->fixtureTable();
        Db::transaction(function (): void {
            $this->write('outer-before');
            try {
                Db::transaction(function (): void {
                    $this->write('nested-rolled-back');
                    throw new RuntimeException('nested failure');
                });
            } catch (RuntimeException $exception) {
                self::assertSame('nested failure', $exception->getMessage());
            }
            $this->write('outer-after');
        });

        self::assertSame(
            ['outer-before', 'outer-after'],
            $this->query('SELECT label FROM fixture_transaction ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN),
        );
    }

    public function testUncaughtNestedFailureRollsBackTheOuterTransaction(): void
    {
        $this->fixtureTable();
        $caught = null;
        try {
            Db::transaction(function (): void {
                $this->write('outer');
                Db::transaction(function (): void {
                    $this->write('nested');
                    throw new RuntimeException('uncaught');
                });
            });
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertSame('uncaught', $caught->getMessage());
        self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM fixture_transaction')->fetchColumn());
    }

    public function testNestedSuccessDoesNotCommitAnExternallyOwnedTransaction(): void
    {
        $this->fixtureTable();
        $connection = Db::connect();
        $connection->startTrans();

        Db::transaction(function (): void {
            $this->write('outer-owned');
            Db::transaction(fn() => $this->write('nested-success'));
        });

        $connection->rollback();
        self::assertSame(0, (int) $this->query('SELECT COUNT(*) FROM fixture_transaction')->fetchColumn());
    }

    private function fixtureTable(): void
    {
        $this->database->exec(<<<'SQL'
CREATE TABLE fixture_transaction (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(80) NOT NULL
) ENGINE=InnoDB
SQL);
    }

    private function write(string $label): void
    {
        Db::table('fixture_transaction')->insert(['label' => $label]);
    }
}
