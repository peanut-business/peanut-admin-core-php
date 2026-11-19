<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

use Psr\Log\LoggerInterface;
use think\facade\Db;
use think\db\PDOConnection;
use RuntimeException;

/** Driver-level advisory locks have no portable Model or query-builder equivalent. */
final readonly class ThinkPhpTenantLockStore implements TenantLockStore
{
    public function __construct(private ?LoggerInterface $logger = null) {}

    public function acquire(TenantScope $scope, string $resourceKey): bool
    {
        $name = TenantNamespace::lockName($scope, $resourceKey);
        $result = $this->connection()->query('SELECT GET_LOCK(:lock_name, 0) AS acquired', ['lock_name' => $name]);

        return (int) ($result[0]['acquired'] ?? 0) === 1;
    }

    public function release(TenantScope $scope, string $resourceKey): void
    {
        $name = TenantNamespace::lockName($scope, $resourceKey);
        try {
            $this->connection()->query('SELECT RELEASE_LOCK(:lock_name)', ['lock_name' => $name]);
        } catch (\Throwable $exception) {
            $this->logger?->warning('TENANT_LOCK_RELEASE_FAILED', [
                'lock_name' => $name,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
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
