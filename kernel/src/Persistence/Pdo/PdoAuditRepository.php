<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Pdo;

use JsonException;
use PeanutAdmin\Kernel\Audit\AuditOutcome;
use PeanutAdmin\Kernel\Audit\AuditRepository;
use PeanutAdmin\Kernel\Auth\TenantContext;

final class PdoAuditRepository extends PdoRepository implements AuditRepository
{
    public function appendPlatform(
        string $eventType,
        string $action,
        string $requestId,
        ?int $operatorId,
        ?int $accountId,
        array $metadata = [],
        AuditOutcome $outcome = AuditOutcome::Success,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_platform_audit_event (
    event_type, action, outcome, operator_id, account_id,
    request_id, metadata_json, occurred_at
) VALUES (
    :event_type, :action, :outcome, :operator_id, :account_id,
    :request_id, :metadata_json, :occurred_at
)
SQL, [
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'operator_id' => $operatorId,
            'account_id' => $accountId,
            'request_id' => $requestId,
            'metadata_json' => $this->metadata($metadata),
            'occurred_at' => $this->now(),
        ]);
    }

    public function appendTenantSystem(
        int $tenantId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
        AuditOutcome $outcome = AuditOutcome::Success,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant_audit_event (
    tenant_id, event_type, action, outcome, actor_tenant_id, actor_type,
    request_id, metadata_json, occurred_at
) VALUES (
    :tenant_id, :event_type, :action, :outcome, :actor_tenant_id, 'tenant_system',
    :request_id, :metadata_json, :occurred_at
)
SQL, [
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'actor_tenant_id' => $tenantId,
            'request_id' => $requestId,
            'metadata_json' => $this->metadata($metadata),
            'occurred_at' => $this->now(),
        ]);
    }

    public function appendTenantMember(
        TenantContext $context,
        string $eventType,
        string $action,
        ?string $targetResourceType = null,
        ?string $targetResourceId = null,
        ?string $boundaryTargetType = null,
        ?string $boundaryTargetId = null,
        int $targetCount = 0,
        ?string $targetSetDigest = null,
        array $metadata = [],
        AuditOutcome $outcome = AuditOutcome::Success,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant_audit_event (
    tenant_id, event_type, action, outcome,
    actor_tenant_id, actor_tenant_member_id, actor_account_id, actor_type,
    target_resource_type, target_resource_id,
    boundary_target_type, boundary_target_id,
    target_count, target_set_digest,
    request_id, metadata_json, occurred_at
) VALUES (
    :tenant_id, :event_type, :action, :outcome,
    :actor_tenant_id, :member_id, :account_id, 'member',
    :target_resource_type, :target_resource_id,
    :boundary_target_type, :boundary_target_id,
    :target_count, :target_set_digest,
    :request_id, :metadata_json, :occurred_at
)
SQL, [
            'tenant_id' => $context->tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'actor_tenant_id' => $context->tenantId,
            'member_id' => $context->memberId,
            'account_id' => $context->accountId,
            'target_resource_type' => $targetResourceType,
            'target_resource_id' => $targetResourceId,
            'boundary_target_type' => $boundaryTargetType,
            'boundary_target_id' => $boundaryTargetId,
            'target_count' => $targetCount,
            'target_set_digest' => $targetSetDigest,
            'request_id' => $context->requestId,
            'metadata_json' => $this->metadata($metadata),
            'occurred_at' => $this->now(),
        ]);
    }

    public function appendTenantPlatformOperator(
        int $tenantId,
        int $operatorId,
        int $accountId,
        string $eventType,
        string $action,
        string $requestId,
        array $metadata = [],
        AuditOutcome $outcome = AuditOutcome::Success,
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant_audit_event (
    tenant_id, event_type, action, outcome,
    actor_account_id, actor_platform_operator_id, actor_type,
    request_id, metadata_json, occurred_at
) VALUES (
    :tenant_id, :event_type, :action, :outcome,
    :account_id, :operator_id, 'platform_operator',
    :request_id, :metadata_json, :occurred_at
)
SQL, [
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'outcome' => $outcome->value,
            'account_id' => $accountId,
            'operator_id' => $operatorId,
            'request_id' => $requestId,
            'metadata_json' => $this->metadata($metadata),
            'occurred_at' => $this->now(),
        ]);
    }

    /**
     * @param array<string, bool|int|string|null> $metadata
     * @throws JsonException
     */
    private function metadata(array $metadata): ?string
    {
        return $metadata === []
            ? null
            : json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
