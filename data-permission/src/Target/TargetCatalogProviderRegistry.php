<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Target;

use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;

final class TargetCatalogProviderRegistry
{
    /** @var array<string, ResourceTargetCatalogProvider> */
    private array $providers = [];

    public function register(string $key, ResourceTargetCatalogProvider $provider): void
    {
        $this->providers[$key] = $provider;
    }

    public function get(string $key): ResourceTargetCatalogProvider
    {
        return $this->providers[$key] ?? throw new DataAuthorizationException(
            'AUTHZ_PROVIDER_MISSING',
            "Target catalog provider {$key} is not registered.",
        );
    }
}
