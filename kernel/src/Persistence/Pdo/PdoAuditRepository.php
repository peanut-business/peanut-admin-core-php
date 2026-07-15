<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Persistence\Pdo;

use JsonException;
use PeanutAdmin\Kernel\Audit\AuditRepository;

final class PdoAuditRepository extends PdoRepository implements AuditRepository
{
    public function appendPlatform(
        string $eventType,
        string $action,
        string $requestId,
        ?int $operatorId,
        ?int $accountId,
        array $metadata = [],
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_platform_audit_event (
    event_type, action, outcome, operator_id, account_id,
    request_id, metadata_json, occurred_at
) VALUES (
    :event_type, :action, 'success', :operator_id, :account_id,
    :request_id, :metadata_json, :occurred_at
)
SQL, [
            'event_type' => $eventType,
            'action' => $action,
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
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant_audit_event (
    tenant_id, event_type, action, outcome, actor_tenant_id, actor_type,
    request_id, metadata_json, occurred_at
) VALUES (
    :tenant_id, :event_type, :action, 'success', :actor_tenant_id, 'tenant_system',
    :request_id, :metadata_json, :occurred_at
)
SQL, [
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
            'actor_tenant_id' => $tenantId,
            'request_id' => $requestId,
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
    ): void {
        $this->execute(<<<'SQL'
INSERT INTO pa_tenant_audit_event (
    tenant_id, event_type, action, outcome,
    actor_account_id, actor_platform_operator_id, actor_type,
    request_id, metadata_json, occurred_at
) VALUES (
    :tenant_id, :event_type, :action, 'success',
    :account_id, :operator_id, 'platform_operator',
    :request_id, :metadata_json, :occurred_at
)
SQL, [
            'tenant_id' => $tenantId,
            'event_type' => $eventType,
            'action' => $action,
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
