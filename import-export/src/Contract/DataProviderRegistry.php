<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport\Contract;

use PeanutAdmin\ImportExport\Application\ImportExportException;

final class DataProviderRegistry
{
    /** @var array<string, DataProvider> */
    private array $providers = [];

    /** @param iterable<DataProvider> $providers */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $key = $provider->key();
            if (preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)*$/D', $key) !== 1 || isset($this->providers[$key])) {
                throw ImportExportException::invalid();
            }
            $this->providers[$key] = $provider;
        }
    }

    public function require(string $key): DataProvider
    {
        return $this->providers[$key] ?? throw ImportExportException::providerUnavailable();
    }
}
