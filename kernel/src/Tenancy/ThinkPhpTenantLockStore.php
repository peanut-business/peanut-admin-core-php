<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

use think\facade\Db;
use think\db\PDOConnection;
use RuntimeException;

/** Driver-level advisory locks have no portable Model or query-builder equivalent. */
final readonly class ThinkPhpTenantLockStore implements TenantLockStore
{
    public function acquire(TenantScope $scope, string $resourceKey): bool
    {
        $name = (new TenantLockNamespace($scope))->name($resourceKey);
        $result = $this->connection()->query('SELECT GET_LOCK(:lock_name, 0) AS acquired', ['lock_name' => $name]);

        return (int) ($result[0]['acquired'] ?? 0) === 1;
    }

    public function release(TenantScope $scope, string $resourceKey): void
    {
        try {
            $name = (new TenantLockNamespace($scope))->name($resourceKey);
            $this->connection()->query('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $name]);
        } catch (\Throwable) {
        }
    }

    private function connection(): PDOConnection
    {
        $connection = Db::connect();
        if (!$connection instanceof PDOConnection) {
            throw new RuntimeException('TENANT_LOCK_DRIVER_UNSUPPORTED');
        }

        return $connection;
    }
}
