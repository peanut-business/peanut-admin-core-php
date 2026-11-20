<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Sequence;

/** Implementations must atomically persist and return the next value for the given key. */
interface AtomicSequenceStore
{
    public function next(string $key): int;
}
