<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Concurrency;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\StorageInterface;

/** Stores are selected explicitly by the host; no implicit fallback changes their guarantees. */
final class ConcurrencyServices
{
    public static function locks(PersistingStoreInterface $store): LockFactory
    {
        return new LockFactory($store);
    }

    /** @param array<string,mixed> $configuration */
    public static function rateLimiter(array $configuration, StorageInterface $storage, ?LockFactory $locks = null): RateLimiterFactory
    {
        return new RateLimiterFactory($configuration, $storage, $locks);
    }
}
