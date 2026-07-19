<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Persistence;

use PeanutAdmin\Kernel\Persistence\Pdo\PdoTransactionManager;
use PeanutAdmin\Kernel\Tests\Integration\Schema\DatabaseTestCase;
use RuntimeException;

require_once dirname(__DIR__) . '/Schema/DatabaseTestCase.php';

final class PdoTransactionManagerTest extends DatabaseTestCase
{
    public function testCaughtNestedFailureRollsBackOnlyItsSavepoint(): void
    {
        $this->fixtureTable();
        $transactions = new PdoTransactionManager($this->database);

        $transactions->run(function () use ($transactions): void {
            $this->write('outer-before');
            try {
                $transactions->run(function (): void {
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
        $transactions = new PdoTransactionManager($this->database);

        $caught = null;
        try {
            $transactions->run(function () use ($transactions): void {
                $this->write('outer');
                $transactions->run(function (): void {
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
        $transactions = new PdoTransactionManager($this->database);
        $this->database->beginTransaction();

        $transactions->run(function () use ($transactions): void {
            $this->write('outer-owned');
            $transactions->run(fn() => $this->write('nested-success'));
        });

        self::assertTrue($this->database->inTransaction());
        $this->database->rollBack();
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
        $statement = $this->database->prepare('INSERT INTO fixture_transaction (label) VALUES (:label)');
        $statement->execute(['label' => $label]);
    }
}
