<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Sequence;

/** An explicitly supplied Redis connection uses atomic INCR. Identical namespace/key pairs share a sequence. */
final readonly class RedisSequenceStore implements AtomicSequenceStore
{
    private \Closure $increment;

    public function __construct(object $redis, private string $namespace)
    {
        if ($namespace === '' || !is_callable([$redis, 'incr'])) {
            throw new \InvalidArgumentException('A namespace and Redis INCR connection are required.');
        }
        $this->increment = \Closure::fromCallable([$redis, 'incr']);
    }

    public function next(string $key): int
    {
        if ($key === '') {
            throw new \InvalidArgumentException('Sequence key is required.');
        }
        $value = ($this->increment)($this->namespace . ':' . $key);
        if (!is_int($value) || $value < 1) {
            throw new \RuntimeException('Redis INCR failed.');
        }
        return $value;
    }
}
