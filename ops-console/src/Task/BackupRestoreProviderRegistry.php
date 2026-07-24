<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Task;

use InvalidArgumentException;
use PeanutAdmin\OpsConsole\Application\OpsConsoleException;
use PeanutAdmin\OpsConsole\Support\Contract;

final class BackupRestoreProviderRegistry
{
    /** @var array<string, BackupRestoreProvider> */
    private array $providers = [];

    /** @param iterable<BackupRestoreProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) $this->register($provider);
    }

    public function register(BackupRestoreProvider $provider): void
    {
        $key = Contract::qualifiedKey($provider->key());
        Contract::qualifiedKey($provider->backupHandlerKey());
        Contract::qualifiedKey($provider->restoreHandlerKey());
        $targets = $provider->restoreTargetKeys();
        if (isset($this->providers[$key]) || $targets === [] || count($targets) > 32
            || $provider->maximumAttempts() < 1 || $provider->maximumAttempts() > 10
        ) {
            throw new InvalidArgumentException('Invalid operations provider registration.');
        }
        $unique = [];
        foreach ($targets as $target) {
            Contract::qualifiedKey($target, 64);
            if (preg_match('/(?:^|[.-])(?:active|current|primary|prod|production)(?:$|[.-])/', $target) === 1
                || isset($unique[$target])
            ) {
                throw new InvalidArgumentException('Unsafe restore target registration.');
            }
            $unique[$target] = true;
        }
        $this->providers[$key] = $provider;
    }

    public function require(string $key): BackupRestoreProvider
    {
        try {
            Contract::qualifiedKey($key);
        } catch (InvalidArgumentException) {
            throw OpsConsoleException::providerNotFound();
        }
        return $this->providers[$key] ?? throw OpsConsoleException::providerNotFound();
    }
}
