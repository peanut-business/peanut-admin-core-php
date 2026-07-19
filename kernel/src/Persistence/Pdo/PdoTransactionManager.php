<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Pdo;

use PDO;
use PeanutAdmin\Kernel\Persistence\TransactionManager;
use RuntimeException;
use Throwable;

final readonly class PdoTransactionManager implements TransactionManager
{
    public function __construct(private PDO $pdo) {}

    public function run(callable $operation): mixed
    {
        if ($this->transactionActive()) {
            return $this->nested($operation);
        }

        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            if (!$this->transactionActive()) {
                throw new RuntimeException('The transaction boundary was closed by the operation.');
            }
            $this->pdo->commit();

            return $result;
        } catch (Throwable $throwable) {
            if ($this->transactionActive()) {
                $this->pdo->rollBack();
            }

            throw $throwable;
        }
    }

    private function nested(callable $operation): mixed
    {
        $savepoint = 'peanut_' . bin2hex(random_bytes(12));
        $this->pdo->exec("SAVEPOINT {$savepoint}");
        try {
            $result = $operation();
            if (!$this->transactionActive()) {
                throw new RuntimeException('The nested transaction boundary was closed by the operation.');
            }
            $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");

            return $result;
        } catch (Throwable $throwable) {
            if ($this->transactionActive()) {
                try {
                    $this->pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
                    $this->pdo->exec("RELEASE SAVEPOINT {$savepoint}");
                } catch (Throwable) {
                    // Preserve the operation failure if the connection already lost the savepoint.
                }
            }

            throw $throwable;
        }
    }

    /** @phpstan-impure */
    private function transactionActive(): bool
    {
        return $this->pdo->inTransaction();
    }
}
