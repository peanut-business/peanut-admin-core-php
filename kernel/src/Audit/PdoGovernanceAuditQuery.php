<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Audit;

use PDO;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Context\PlatformContext;
use PeanutAdmin\Kernel\Platform\Application\PlatformWorkspaceQueryService;
use PeanutAdmin\Kernel\Tenancy\Application\TenantWorkspaceQueryService;
use RuntimeException;

final readonly class PdoGovernanceAuditQuery implements GovernanceAuditQuery
{
    public function __construct(private PDO $pdo) {}

    public function tenant(TenantContext $context, GovernanceAuditFilter $filter, PageRequest $page): GovernanceAuditPage
    {
        $result = (new TenantWorkspaceQueryService($this->pdo))->auditEvents($context->tenantId, $page, $filter);

        return new GovernanceAuditPage(
            array_map(fn(array $row): GovernanceAuditEvent => $this->event($row, 'tenant'), $result['items']),
            $result['total'],
        );
    }

    public function tenantDetail(TenantContext $context, string $eventId): GovernanceAuditEvent
    {
        return $this->event(
            (new TenantWorkspaceQueryService($this->pdo))->auditEvent($context->tenantId, $eventId),
            'tenant',
        );
    }

    public function platform(PlatformContext $context, GovernanceAuditFilter $filter, PageRequest $page): GovernanceAuditPage
    {
        $result = (new PlatformWorkspaceQueryService($this->pdo))->auditEvents($page, $filter);

        return new GovernanceAuditPage(
            array_map(fn(array $row): GovernanceAuditEvent => $this->event($row, 'platform'), $result['items']),
            $result['total'],
        );
    }

    public function platformDetail(PlatformContext $context, string $eventId): GovernanceAuditEvent
    {
        return $this->event((new PlatformWorkspaceQueryService($this->pdo))->auditEvent($eventId), 'platform');
    }

    /** @param array<string, mixed> $row */
    private function event(array $row, string $audience): GovernanceAuditEvent
    {
        $outcome = AuditOutcome::tryFrom((string) ($row['outcome'] ?? ''));
        if ($outcome === null) {
            throw new RuntimeException('Stored audit outcome is invalid.');
        }

        return new GovernanceAuditEvent(
            (string) ($row['id'] ?? ''),
            $audience,
            (string) ($row['event_type'] ?? ''),
            (string) ($row['action'] ?? ''),
            $outcome,
            (string) ($row['request_id'] ?? ''),
            (string) ($row['created_at'] ?? ''),
            isset($row['actor_type']) ? (string) $row['actor_type'] : null,
            isset($row['target_resource_type'])
                ? (string) $row['target_resource_type']
                : (isset($row['target_type']) ? (string) $row['target_type'] : null),
            isset($row['target_resource_id'])
                ? (string) $row['target_resource_id']
                : (isset($row['target_id']) ? (string) $row['target_id'] : null),
            is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
        );
    }
}
