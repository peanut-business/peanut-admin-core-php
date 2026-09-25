<?php

declare(strict_types=1);

namespace PeanutAdmin\Testing\Tests\Support;

use PDO;
use think\Container;
use think\DbManager;
use think\db\ConnectionInterface;
use think\db\PDOConnection;
use think\db\builder\Sqlite as SqliteBuilder;
use think\db\connector\Sqlite;

final class ThinkPhpTestConnection
{
    private function __construct() {}

    public static function fromPdo(PDO $pdo): PDOConnection
    {
        $connection = new SharedPdoSqliteConnection($pdo);
        $manager = new SharedPdoDbManager($connection);
        $connection->setDb($manager);
        Container::getInstance()->instance(DbManager::class, $manager);

        return $connection;
    }
}

final class SharedPdoDbManager extends DbManager
{
    public function __construct(private readonly ConnectionInterface $connection)
    {
        parent::__construct();
    }

    protected function instance(string|array|null $name = null, bool $force = false): ConnectionInterface
    {
        return $this->connection;
    }
}

final class SharedPdoSqliteConnection extends Sqlite
{
    public function __construct(private readonly PDO $sharedPdo)
    {
        parent::__construct([
            'type' => 'sqlite',
            'builder' => SqliteBuilder::class,
            'prefix' => 'pa_',
        ]);
    }

    protected function createPdo($dsn, $username, $password, $params): PDO
    {
        return $this->sharedPdo;
    }
}
