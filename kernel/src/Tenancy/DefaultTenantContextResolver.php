<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tenancy;

use PDO;
use PeanutAdmin\Kernel\Context\TenantSystemContext;

final readonly class DefaultTenantContextResolver
{
    public function __construct(private PDO $pdo) {}

    public function system(string $actor, string $operation, string $operationId): TenantSystemContext
    {
        $actor = trim($actor);
        $operation = trim($operation);
        $operationId = trim($operationId);
        if ($actor === '' || $operation === '' || $operationId === '') {
            throw new \DomainException('DEFAULT_TENANT_CONTEXT_UNAVAILABLE');
        }
        $statement = $this->pdo->prepare("SELECT id FROM pa_tenant WHERE code = 'default' AND status = 'active' LIMIT 2");
        $statement->execute();
        $ids = $statement->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) !== 1 || (int) $ids[0] < 1) {
            throw new \DomainException('DEFAULT_TENANT_CONTEXT_UNAVAILABLE');
        }
        return new TenantSystemContext((int) $ids[0], $actor, $operation, $operationId);
    }

    public static function operationId(object $request): string
    {
        if (!method_exists($request, 'header')) {
            throw new \DomainException('DEFAULT_TENANT_CONTEXT_UNAVAILABLE');
        }
        $operationId = trim((string) $request->header('X-Request-Id', ''));
        return $operationId !== '' ? $operationId : bin2hex(random_bytes(16));
    }
}
