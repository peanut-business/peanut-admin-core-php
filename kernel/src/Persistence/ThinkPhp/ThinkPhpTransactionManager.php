<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\ThinkPhp;

use PeanutAdmin\Kernel\Persistence\TransactionManager;
use think\db\ConnectionInterface;

/** ThinkPHP-owned transaction boundary with native nested savepoint semantics. */
final readonly class ThinkPhpTransactionManager implements TransactionManager
{
    public function __construct(private ConnectionInterface $connection) {}

    public function run(callable $operation): mixed
    {
        return $this->connection->transaction(static fn(): mixed => $operation());
    }
}
