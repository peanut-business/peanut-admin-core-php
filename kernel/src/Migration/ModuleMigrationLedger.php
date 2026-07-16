<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Migration;

use PeanutAdmin\Kernel\Module\ModuleException;

final class ModuleMigrationLedger
{
    /** @var array<string, MigrationRecord> */
    private array $records = [];

    /** @param list<MigrationRecord> $records */
    public function __construct(array $records)
    {
        foreach ($records as $record) {
            $this->records[$record->moduleKey . ':' . $record->migrationKey] = $record;
        }
    }

    public function shouldApply(string $moduleKey, string $migrationKey, string $checksum): bool
    {
        $record = $this->records[$moduleKey . ':' . $migrationKey] ?? null;
        if ($record === null || $record->status === 'rolled_back') {
            return true;
        }
        if (!hash_equals($record->checksum, $checksum)) {
            throw new ModuleException(
                'MODULE_MIGRATION_CHECKSUM_MISMATCH',
                "Applied migration changed: {$moduleKey}:{$migrationKey}",
            );
        }

        return $record->status !== 'applied';
    }
}
