<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Task;

use InvalidArgumentException;
use PeanutAdmin\OpsConsole\Application\OpsConsoleException;
use PeanutAdmin\OpsConsole\Support\Contract;

final class BackupRestoreProviderRegistry
{
    /** @var array<string, BackupRestoreProviderDescriptor> */
    private array $providers = [];

    /** @param iterable<BackupRestoreProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->register($provider);
        }
    }

    public function register(BackupRestoreProvider $provider): void
    {
        $descriptor = new BackupRestoreProviderDescriptor(
            $provider,
            $provider->key(),
            $provider->backupHandlerKey(),
            $provider->restoreHandlerKey(),
            array_values($provider->restoreTargetKeys()),
            $provider->maximumAttempts(),
        );
        if (isset($this->providers[$descriptor->key])) {
            throw new InvalidArgumentException('Invalid operations provider registration.');
        }
        $this->providers[$descriptor->key] = $descriptor;
    }

    public function require(string $key): BackupRestoreProviderDescriptor
    {
        try {
            Contract::qualifiedKey($key);
        } catch (InvalidArgumentException) {
            throw OpsConsoleException::providerNotFound();
        }
        return $this->providers[$key] ?? throw OpsConsoleException::providerNotFound();
    }
}
