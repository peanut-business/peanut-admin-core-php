<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Migration;

use PeanutAdmin\Kernel\Module\ModuleException;

final readonly class OwnedTableRegistry
{
    /** @param array<string, string> $ownersByTable */
    public function __construct(private array $ownersByTable) {}

    public function table(string $moduleKey, string $table): string
    {
        $owner = $this->ownersByTable[$table] ?? null;
        if ($owner !== $moduleKey) {
            throw new ModuleException('MODULE_REGISTRY_CONFLICT', "{$moduleKey} does not own table {$table}.");
        }

        return $table;
    }
}
