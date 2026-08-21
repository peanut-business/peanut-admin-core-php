<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Governance;

final readonly class GovernancePermission
{
    public function __construct(
        public string $key,
        public string $moduleKey,
        public string $audience,
        public bool $active,
    ) {
        if (!in_array($audience, ['tenant', 'platform'], true)
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z][a-z0-9-]*)+$/D', $key) !== 1
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z][a-z0-9-]*)*$/D', $moduleKey) !== 1
            || ($audience === 'platform') !== str_starts_with($key, 'platform.')) {
            throw new GovernanceException(
                'GOVERNANCE_PERMISSION_INVALID',
                'The governance Permission declaration is invalid.',
            );
        }
    }
}
