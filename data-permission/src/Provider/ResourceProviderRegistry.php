<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;

final class ResourceProviderRegistry
{
    /** @var array<string, ResourcePolicyProvider> */
    private array $providers = [];

    public function register(string $key, ResourcePolicyProvider $provider): void
    {
        $this->providers[$key] = $provider;
    }

    public function get(string $key): ResourcePolicyProvider
    {
        return $this->providers[$key] ?? throw new DataAuthorizationException(
            'AUTHZ_PROVIDER_MISSING',
            "Resource provider {$key} is not registered.",
        );
    }

    public function query(string $key): ResourcePolicyProvider
    {
        return $this->get($key);
    }

    public function target(string $key): ResourcePolicyProvider
    {
        return $this->get($key);
    }

    public function create(string $key): ResourcePolicyProvider
    {
        return $this->get($key);
    }
}
