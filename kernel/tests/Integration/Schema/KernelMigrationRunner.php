<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Schema;

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use think\console\Input;
use think\migration\NullOutput;

final readonly class KernelMigrationRunner
{
    public function __construct(
        private string $database,
        private string $host,
        private int $port,
        private string $username,
        private string $password,
    ) {}

    public function migrate(?int $targetVersion = null): void
    {
        $this->manager()->migrate('kernel', $targetVersion);
    }

    public function rollbackAll(): void
    {
        $this->manager()->rollback('kernel', 'all', true);
    }

    private function manager(): Manager
    {
        $config = new Config([
            'paths' => [
                'migrations' => dirname(__DIR__, 3) . '/database/migrations',
            ],
            'environments' => [
                'default_environment' => 'kernel',
                'default_migration_table' => 'pa_kernel_migration',
                'kernel' => [
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
        ]);

        return new Manager($config, new Input([]), new NullOutput());
    }
}
