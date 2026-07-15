<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Audit;

interface AuditRepository
{
    /** @param array<string, bool|int|string|null> $metadata */
    public function appendPlatform(
        string $eventType,
        string $action,
        string $requestId,
        ?int $operatorId,
        ?int $accountId,
        array $metadata = [],
    ): void;

    /** @param array<string, bool|int|string|null> $metadata */
    public function appendTenantSystem(
        int $tenantId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
    ): void;

    /** @param array<string, bool|int|string|null> $metadata */
    public function appendTenantPlatformOperator(
        int $tenantId,
        int $operatorId,
        int $accountId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
    ): void;
}
