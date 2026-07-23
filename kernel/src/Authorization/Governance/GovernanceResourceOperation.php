<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Governance;

final readonly class GovernanceResourceOperation
{
    /** @param list<string> $conditionKeys */
    public function __construct(
        public string $resourceKey,
        public string $operation,
        public string $moduleKey,
        public string $audience,
        public array $conditionKeys,
    ) {
        if (!in_array($audience, ['tenant', 'platform'], true)
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z][a-z0-9-]*)+$/D', $resourceKey) !== 1
            || preg_match('/^[a-z][a-z0-9._-]*$/D', $operation) !== 1) {
            throw new GovernanceException('GOVERNANCE_OPERATION_INVALID', 'The governance operation declaration is invalid.');
        }
    }
}
