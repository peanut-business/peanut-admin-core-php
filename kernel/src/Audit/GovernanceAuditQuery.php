<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Audit;

use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedPlatformSession;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;

interface GovernanceAuditQuery
{
    public function tenant(TenantContext $context, GovernanceAuditFilter $filter, PageRequest $page): GovernanceAuditPage;

    public function tenantDetail(TenantContext $context, string $eventId): GovernanceAuditEvent;

    public function platform(ValidatedPlatformSession $session, GovernanceAuditFilter $filter, PageRequest $page): GovernanceAuditPage;

    public function platformDetail(ValidatedPlatformSession $session, string $eventId): GovernanceAuditEvent;
}
