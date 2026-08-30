<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Tests\Integration\Schema;

use PDO;
use PeanutAdmin\Kernel\Persistence\Tenancy\TenantPersistenceMode;
use PeanutAdmin\Settings\Database\Schema;

final readonly class SettingsMigrationRunner
{
    public function __construct(
        private PDO $pdo,
        private TenantPersistenceMode $mode = TenantPersistenceMode::TenantScoped,
    ) {}

    public function migrate(): void
    {
        foreach (Schema::tableNames() as $table) {
            if (!$this->exists($table)) {
                $this->pdo->exec(Schema::createSql($table, $this->mode));
            }
        }
    }

    public function rollbackAll(): void
    {
        foreach (array_reverse(Schema::tableNames()) as $table) {
            $this->pdo->exec(Schema::dropSql($table));
        }
    }

    private function exists(string $table): bool
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT COUNT(*) FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name = :table_name
SQL);
        $statement->execute(['table_name' => $table]);

        return (int) $statement->fetchColumn() === 1;
    }
}
