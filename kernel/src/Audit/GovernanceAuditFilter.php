<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Audit;

use InvalidArgumentException;

final readonly class GovernanceAuditFilter
{
    public function __construct(
        public ?string $eventType = null,
        public ?string $action = null,
        public ?AuditOutcome $outcome = null,
        public ?string $requestId = null,
        public ?string $targetType = null,
        public ?string $targetId = null,
    ) {
        foreach ([$eventType, $action, $requestId, $targetType, $targetId] as $value) {
            if ($value !== null && ($value === '' || strlen($value) > 160 || preg_match('/[\x00-\x1f\x7f]/', $value) === 1)) {
                throw new InvalidArgumentException('AUDIT_FILTER_INVALID');
            }
        }
        if (($targetType === null) !== ($targetId === null)) {
            throw new InvalidArgumentException('AUDIT_TARGET_FILTER_INCOMPLETE');
        }
    }
}
