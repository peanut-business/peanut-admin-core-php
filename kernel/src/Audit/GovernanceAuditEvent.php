<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Audit;

final readonly class GovernanceAuditEvent
{
    /** @param array<string, bool|int|string|null> $metadata */
    public function __construct(
        public string $id,
        public string $audience,
        public string $eventType,
        public string $action,
        public AuditOutcome $outcome,
        public string $requestId,
        public string $occurredAt,
        public ?string $actorType,
        public ?string $targetType,
        public ?string $targetId,
        public array $metadata,
    ) {}
}
