<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Menu;

use PeanutAdmin\Kernel\Authorization\Governance\GovernanceException;

final class GovernanceRoutePath
{
    public static function requireCanonical(string $path, string $audience): string
    {
        if (!in_array($audience, ['tenant', 'platform'], true)
            || $path === ''
            || $path[0] !== '/'
            || $path !== rtrim($path, '/')
            || str_contains($path, '//')
            || str_contains($path, '\\')
            || str_contains($path, '%')
            || str_contains($path, '?')
            || str_contains($path, '#')
            || preg_match('/[\x00-\x20\x7f]/', $path) === 1) {
            throw new GovernanceException('GOVERNANCE_ROUTE_INVALID', 'The governance route path is not canonical.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new GovernanceException('GOVERNANCE_ROUTE_INVALID', 'The governance route path contains a dot segment.');
            }
        }

        $prefix = $audience === 'tenant' ? '/app' : '/platform';
        if ($path !== $prefix && !str_starts_with($path, $prefix . '/')) {
            throw new GovernanceException('GOVERNANCE_ROUTE_INVALID', 'The governance route path belongs to another audience.');
        }

        return $path;
    }

    private function __construct() {}
}
