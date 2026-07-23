<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Menu;

use PeanutAdmin\Kernel\Authorization\Governance\GovernanceException;

final readonly class GovernanceRoute
{
    /** @param list<string> $permissionKeys */
    public function __construct(
        public string $name,
        public string $path,
        public string $audience,
        public ?string $moduleKey,
        public array $permissionKeys,
    ) {
        $prefix = $audience === 'tenant' ? '/app' : '/platform';
        if (!in_array($audience, ['tenant', 'platform'], true)
            || $name === ''
            || ($path !== $prefix && !str_starts_with($path, $prefix . '/'))
            || str_starts_with($path, '//')
            || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            throw new GovernanceException('GOVERNANCE_ROUTE_INVALID', 'The governance route declaration is invalid.');
        }
    }
}
