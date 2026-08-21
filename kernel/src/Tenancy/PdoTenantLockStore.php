<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

use PDO;

final readonly class PdoTenantLockStore implements TenantLockStore
{
    public function __construct(private PDO $pdo) {}

    public function acquire(TenantScope $scope, string $resourceKey): bool
    {
        $name = (new TenantLockNamespace($scope))->name($resourceKey);
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:lock_name, 0)');
        $statement->execute(['lock_name' => $name]);

        return (int) $statement->fetchColumn() === 1;
    }

    public function release(TenantScope $scope, string $resourceKey): void
    {
        try {
            $name = (new TenantLockNamespace($scope))->name($resourceKey);
            $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $statement->execute(['lock_name' => $name]);
        } catch (\Throwable) {
        }
    }
}
