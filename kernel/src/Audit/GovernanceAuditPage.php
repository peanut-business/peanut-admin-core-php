<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Audit;

final readonly class GovernanceAuditPage
{
    /** @param list<GovernanceAuditEvent> $items */
    public function __construct(public array $items, public int $total) {}
}
