<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Audit;

use PeanutAdmin\Kernel\Auth\TenantContext;

/** Transactional audit boundary; implementations join the caller's connection/transaction. */
interface AuditWriter
{
    /** @param array<string,mixed> $metadata */
    public function tenantMember(
        TenantContext $context,
        string $eventType,
        string $action,
        ?string $targetResourceType = null,
        ?string $targetResourceId = null,
        array $metadata = [],
        ?int $targetCount = null,
        ?string $boundaryTargetType = null,
        ?string $boundaryTargetId = null,
        ?string $targetSetDigest = null,
        AuditOutcome $outcome = AuditOutcome::Success,
    ): void;

    /** @param array<string,mixed> $metadata
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public function platform(
        int $operatorId,
        int $accountId,
        string $requestId,
        string $eventType,
        string $action,
        array $metadata = [],
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $before = null,
        ?array $after = null,
        AuditOutcome $outcome = AuditOutcome::Success,
    ): void;
}
