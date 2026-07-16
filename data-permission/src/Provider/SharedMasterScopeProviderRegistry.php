<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;

final class SharedMasterScopeProviderRegistry
{
    /** @var array<string, SharedMasterScopeProvider> */
    private array $providers = [];

    public function register(string $resourceKey, SharedMasterScopeProvider $provider): void
    {
        $this->providers[$resourceKey] = $provider;
    }

    public function get(string $resourceKey): SharedMasterScopeProvider
    {
        return $this->providers[$resourceKey] ?? throw new DataAuthorizationException(
            'AUTHZ_PROVIDER_MISSING',
            "Shared-master provider {$resourceKey} is not registered.",
        );
    }
}
