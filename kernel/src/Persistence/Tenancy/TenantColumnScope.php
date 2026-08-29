<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Tenancy;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use WeakMap;

final class TenantColumnScope
{
    /** @var WeakMap<PDO, array<string, true>> */
    private WeakMap $validatedStorageModes;

    public function __construct(
        public readonly TenantPersistenceMode $mode = TenantPersistenceMode::TenantScoped,
        private readonly ?int $instanceTenantId = null,
    ) {
        $this->validatedStorageModes = new WeakMap();
        if (($this->mode === TenantPersistenceMode::TenantScoped && $this->instanceTenantId !== null)
            || ($this->instanceTenantId !== null && $this->instanceTenantId < 1)) {
            throw new RuntimeException('TENANT_PERSISTENCE_CONFIGURATION_INVALID');
        }
    }

    public function usesTenantColumn(): bool
    {
        return $this->mode->usesTenantColumn();
    }

    public function whenTenant(string $sql): string
    {
        return $this->usesTenantColumn() ? $sql : '';
    }

    public function columns(string $remaining): string
    {
        return $this->usesTenantColumn() ? 'tenant_id, ' . $remaining : $remaining;
    }

    public function values(string $remaining): string
    {
        return $this->usesTenantColumn() ? ':tenant_id, ' . $remaining : $remaining;
    }

    public function where(string $remaining, string $qualifiedColumn = 'tenant_id'): string
    {
        $this->assertIdentifier($qualifiedColumn);
        return $this->usesTenantColumn()
            ? $qualifiedColumn . ' = :tenant_id AND ' . $remaining
            : $remaining;
    }

    public function andWhere(string $qualifiedColumn = 'tenant_id'): string
    {
        $this->assertIdentifier($qualifiedColumn);
        return $this->usesTenantColumn() ? ' AND ' . $qualifiedColumn . ' = :tenant_id' : '';
    }

    public function join(string $left, string $right): string
    {
        $this->assertIdentifier($left);
        $this->assertIdentifier($right);
        return $this->usesTenantColumn() ? $left . ' = ' . $right . ' AND ' : '';
    }

    /**
     * @param array<string, mixed> $remaining
     * @return array<string, mixed>
     */
    public function bindings(int $tenantId, array $remaining = []): array
    {
        $this->assertTenantId($tenantId);
        if (array_key_exists('tenant_id', $remaining)) {
            throw new RuntimeException('TENANT_PERSISTENCE_BINDING_COLLISION');
        }
        return $this->usesTenantColumn()
            ? ['tenant_id' => $tenantId, ...$remaining]
            : $remaining;
    }

    public function bind(PDOStatement $statement, int $tenantId, string $parameter = 'tenant_id'): void
    {
        $this->assertTenantId($tenantId);
        $this->assertIdentifier($parameter);
        if ($this->usesTenantColumn()) {
            $statement->bindValue($parameter, $tenantId, PDO::PARAM_INT);
        }
    }

    /** @param array<string, mixed> $row */
    public function tenantId(array $row, int $logicalTenantId): int
    {
        $this->assertTenantId($logicalTenantId);
        $this->assertStorageRow($row);
        if (!$this->usesTenantColumn()) {
            return $logicalTenantId;
        }
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
        if (array_key_exists('tenant_id', $row) !== $this->usesTenantColumn()) {
            throw new RuntimeException('TENANT_PERSISTENCE_SCHEMA_MODE_MISMATCH');
        }
    }

    public function assertTenantId(int $tenantId): void
    {
        if ($tenantId < 1
            || ($this->mode === TenantPersistenceMode::InstanceScoped
                && ($this->instanceTenantId === null || $tenantId !== $this->instanceTenantId))) {
            throw new RuntimeException('TENANT_PERSISTENCE_CONTEXT_INVALID');
        }
    }

    public function assertRuntimeConfigured(): void
    {
        if ($this->mode === TenantPersistenceMode::InstanceScoped && $this->instanceTenantId === null) {
            throw new RuntimeException('TENANT_PERSISTENCE_CONFIGURATION_REQUIRED');
        }
    }

    /** @param list<string> $tables */
    public function assertStorageMode(PDO $pdo, array $tables): void
    {
        if ($tables === [] || count(array_unique($tables)) !== count($tables)) {
            throw new RuntimeException('TENANT_PERSISTENCE_CONFIGURATION_INVALID');
        }

        $validationKey = implode("\0", $tables);
        $validated = $this->validatedStorageModes[$pdo] ?? [];
        if (isset($validated[$validationKey])) {
            return;
        }

        $parameters = [];
        $placeholders = [];
        foreach (array_values($tables) as $index => $table) {
            $this->assertIdentifier($table);
            $parameter = 'table_' . $index;
            $parameters[$parameter] = $table;
            $placeholders[] = ':' . $parameter;
        }

        try {
            $statement = $pdo->prepare(sprintf(<<<'SQL'
SELECT TABLE_NAME,
       MAX(CASE WHEN COLUMN_NAME = 'tenant_id' THEN 1 ELSE 0 END) AS has_tenant_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (%s)
GROUP BY TABLE_NAME
SQL, implode(', ', $placeholders)));
            $statement->execute($parameters);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $exception) {
            throw new RuntimeException('TENANT_PERSISTENCE_SCHEMA_MODE_MISMATCH', 0, $exception);
        }

        $expected = $this->usesTenantColumn() ? 1 : 0;
        $actual = [];
        foreach ($rows as $row) {
            $actual[(string) $row['TABLE_NAME']] = (int) $row['has_tenant_column'];
        }
        foreach ($tables as $table) {
            if (!array_key_exists($table, $actual) || $actual[$table] !== $expected) {
                throw new RuntimeException('TENANT_PERSISTENCE_SCHEMA_MODE_MISMATCH');
            }
        }
        $validated[$validationKey] = true;
        $this->validatedStorageModes[$pdo] = $validated;
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?$/D', $identifier) !== 1) {
            throw new RuntimeException('TENANT_PERSISTENCE_SQL_IDENTIFIER_INVALID');
        }
    }
}
