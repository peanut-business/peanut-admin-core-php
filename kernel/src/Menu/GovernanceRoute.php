<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Menu;

use PeanutAdmin\Kernel\Authorization\Governance\GovernanceException;

final readonly class GovernanceRoute
{
    /**
     * @param list<string> $permissionKeys
     * @param list<string> $clientKeys
     */
    public function __construct(
        public string $name,
        public string $path,
        public string $audience,
        public ?string $moduleKey,
        public array $permissionKeys,
        public string $componentKey,
        public array $clientKeys,
    ) {
        if (!in_array($audience, ['tenant', 'platform'], true)
            || $name === ''
            || $componentKey === ''
            || $permissionKeys === []
            || $clientKeys === []
            || count(array_unique($permissionKeys, SORT_STRING)) !== count($permissionKeys)
            || count(array_unique($clientKeys, SORT_STRING)) !== count($clientKeys)) {
            throw new GovernanceException('GOVERNANCE_ROUTE_INVALID', 'The governance route declaration is invalid.');
        }
        GovernanceRoutePath::requireCanonical($path, $audience);
    }
}
