<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context\Persistence;

use PDO;
use PeanutAdmin\Kernel\Context\SystemTenantResolver;
use RuntimeException;

final readonly class PdoSystemTenantResolver implements SystemTenantResolver
{
    public function __construct(private PDO $pdo) {}

    public function activeTenantIdByCode(string $tenantCode): ?int
    {
        $statement = $this->pdo->prepare(<<<'SQL'
SELECT id FROM pa_tenant WHERE code = :tenant_code AND status = 'active'
SQL);
        if ($statement === false) {
            throw new RuntimeException('Could not prepare tenant lookup.');
        }
        $statement->execute(['tenant_code' => $tenantCode]);
        $tenantId = $statement->fetchColumn();

        return $tenantId === false ? null : (int) $tenantId;
    }
}
