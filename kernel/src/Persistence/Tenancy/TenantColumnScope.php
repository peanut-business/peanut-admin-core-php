<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Tenancy;

use RuntimeException;
use think\facade\Db;

/**
 * Validates persisted rows and tables at the tenant-column boundary.
 */
final class TenantColumnScope
{
    /** @var array<string, true> */
    private array $validatedStorageModes = [];

    /** @param array<string, mixed> $row */
    public function tenantId(array $row, int $logicalTenantId): int
    {
        $this->assertTenantId($logicalTenantId);
        $this->assertStorageRow($row);
        $stored = $row['tenant_id'] ?? null;
        if ((!is_int($stored) && !(is_string($stored) && ctype_digit($stored)))
            || (int) $stored !== $logicalTenantId) {
            throw new RuntimeException('TENANT_PERSISTENCE_SCOPE_MISMATCH');
        }
        return $logicalTenantId;
    }

    /** @param array<string, mixed> $row */
    public function assertStorageRow(array $row): void
    {
        if (!array_key_exists('tenant_id', $row)) {
            throw new RuntimeException('TENANT_PERSISTENCE_SCHEMA_MODE_MISMATCH');
        }
    }

    public function assertTenantId(int $tenantId): void
    {
        if ($tenantId < 1) {
            throw new RuntimeException('TENANT_PERSISTENCE_CONTEXT_INVALID');
        }
    }

    /** @param list<string> $tables */
    public function assertStorageMode(array $tables): void
    {
        if ($tables === [] || count(array_unique($tables)) !== count($tables)) {
            throw new RuntimeException('TENANT_PERSISTENCE_CONFIGURATION_INVALID');
        }

        $validationKey = implode("\0", $tables);
        if (isset($this->validatedStorageModes[$validationKey])) {
            return;
        }
        foreach ($tables as $table) {
            $this->assertIdentifier($table);
        }
        try {
            $rows = Db::table('information_schema.COLUMNS')
                ->where('TABLE_SCHEMA', Db::raw('DATABASE()'))
                ->whereIn('TABLE_NAME', $tables)
                ->field('TABLE_NAME,COLUMN_NAME')
                ->select()
                ->toArray();
        } catch (\Throwable $exception) {
            throw new RuntimeException('TENANT_PERSISTENCE_SCHEMA_MODE_MISMATCH', 0, $exception);
        }

        $actual = [];
        foreach ($rows as $row) {
            $name = (string) $row['TABLE_NAME'];
            $actual[$name] ??= 0;
            if ($row['COLUMN_NAME'] === 'tenant_id') {
                $actual[$name] = 1;
            }
        }
        foreach ($tables as $table) {
            if (!array_key_exists($table, $actual) || $actual[$table] !== 1) {
                throw new RuntimeException('TENANT_PERSISTENCE_SCHEMA_MODE_MISMATCH');
            }
        }
        $this->validatedStorageModes[$validationKey] = true;
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?$/D', $identifier) !== 1) {
            throw new RuntimeException('TENANT_PERSISTENCE_SQL_IDENTIFIER_INVALID');
        }
    }
}
