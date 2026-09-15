<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Audit;

use PeanutAdmin\Kernel\Audit\Model\PlatformAuditEventRecord;
use PeanutAdmin\Kernel\Audit\Model\TenantAuditEventRecord;
use PeanutAdmin\Kernel\Auth\TenantContext;
use think\db\Raw;

final readonly class AuditService
{
    /** @param array<string, mixed> $metadata */
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
    ): void {
        (new TenantAuditEventRecord())->save([
            'tenant_id' => $context->tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'actor_tenant_id' => $context->tenantId,
            'actor_tenant_member_id' => $context->memberId,
            'actor_account_id' => $context->accountId,
            'actor_type' => 'member',
            'target_resource_type' => $targetResourceType,
            'target_resource_id' => $targetResourceId,
            'boundary_target_type' => $boundaryTargetType,
            'boundary_target_id' => $boundaryTargetId,
            'target_count' => $targetCount ?? ($targetResourceId === null ? 0 : 1),
            'target_set_digest' => $targetSetDigest,
            'request_id' => $context->requestId,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }

    /** @param array<string, mixed> $metadata */
    public function tenantSystem(
        int $tenantId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
        AuditOutcome $outcome = AuditOutcome::Success,
    ): void {
        (new TenantAuditEventRecord())->save([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'actor_tenant_id' => $tenantId,
            'actor_type' => 'tenant_system',
            'target_count' => 0,
            'request_id' => $requestId,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }

    /** @param array<string, mixed> $metadata
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
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
    ): void {
        (new PlatformAuditEventRecord())->save([
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'operator_id' => $operatorId,
            'account_id' => $accountId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_id' => $requestId,
            'before_json' => $before,
            'after_json' => $after,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }

    /** @param array<string, mixed> $metadata
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    public function tenantPlatformOperator(
        int $tenantId,
        int $operatorId,
        int $accountId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
        ?string $targetType = null,
        ?string $targetId = null,
        ?array $before = null,
        ?array $after = null,
        AuditOutcome $outcome = AuditOutcome::Success,
    ): void {
        (new TenantAuditEventRecord())->save([
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'actor_tenant_id' => null,
            'actor_platform_operator_id' => $operatorId,
            'actor_account_id' => $accountId,
            'actor_type' => 'platform_operator',
            'target_resource_type' => $targetType,
            'target_resource_id' => $targetId,
            'target_count' => $targetId === null ? 0 : 1,
            'request_id' => $requestId,
            'before_json' => $before,
            'after_json' => $after,
            'metadata_json' => $metadata === [] ? null : $metadata,
            'occurred_at' => new Raw('UTC_TIMESTAMP(3)'),
        ]);
    }
}
