<?php

declare(strict_types=1);

namespace PeanutAdmin\OpsConsole\Maintenance;

use InvalidArgumentException;
use PeanutAdmin\OpsConsole\Application\OpsConsoleException;
use PeanutAdmin\OpsConsole\Support\Contract;

final class MaintenanceReasonRegistry
{
    /** @var array<string, true> */
    private array $keys = [];

    /** @param iterable<string> $keys */
    public function __construct(iterable $keys)
    {
        foreach ($keys as $key) {
            Contract::qualifiedKey($key, 64);
            if (isset($this->keys[$key])) {
                throw new InvalidArgumentException('Duplicate maintenance reason.');
            }
            $this->keys[$key] = true;
        }
        if ($this->keys === [] || count($this->keys) > 32) {
            throw new InvalidArgumentException('Missing maintenance reasons.');
        }
    }

    public function require(string $key): string
    {
        return isset($this->keys[$key]) ? $key : throw OpsConsoleException::maintenanceInvalid();
    }
}
