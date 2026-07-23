<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Audit;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use PeanutAdmin\Kernel\Context\PlatformContext;

interface GovernanceAuditQuery
{
    public function tenant(TenantContext $context, GovernanceAuditFilter $filter, PageRequest $page): GovernanceAuditPage;

    public function tenantDetail(TenantContext $context, string $eventId): GovernanceAuditEvent;

    public function platform(PlatformContext $context, GovernanceAuditFilter $filter, PageRequest $page): GovernanceAuditPage;

    public function platformDetail(PlatformContext $context, string $eventId): GovernanceAuditEvent;
}
