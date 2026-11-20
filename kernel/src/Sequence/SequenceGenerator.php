<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Sequence;

final readonly class SequenceGenerator
{
    public function __construct(private AtomicSequenceStore $store) {}

    public function next(string $key): int
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Sequence key is required.');
        }
        return $this->store->next($key);
    }
}
