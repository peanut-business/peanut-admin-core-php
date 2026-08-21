<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence;

interface TransactionManager
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed;
}
