<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Integration\Schema;

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use think\console\Input;
use think\migration\NullOutput;

final readonly class DataPermissionMigrationRunner
{
    public function __construct(
        private string $database,
        private string $host,
        private int $port,
        private string $username,
        private string $password,
    ) {}

    public function migrate(): void
    {
        $this->manager()->migrate('data_permission');
    }

    public function rollbackAll(): void
    {
        $this->manager()->rollback('data_permission', 'all', true);
    }

    private function manager(): Manager
    {
        return new Manager(new Config([
            'paths' => ['migrations' => dirname(__DIR__, 3) . '/database/migrations'],
            'environments' => [
                'default_environment' => 'data_permission',
                'default_migration_table' => 'pa_data_permission_migration',
                'data_permission' => [
                    'adapter' => 'mysql',
                    'host' => $this->host,
                    'port' => $this->port,
                    'name' => $this->database,
                    'user' => $this->username,
                    'pass' => $this->password,
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_0900_ai_ci',
                ],
            ],
            'version_order' => Config::VERSION_ORDER_CREATION_TIME,
        ]), new Input([]), new NullOutput());
    }
}
