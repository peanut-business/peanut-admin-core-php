<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;

final class ResourceProviderRegistry
{
    /** @var array<string, ResourceQueryPolicyProvider> */
    private array $queryProviders = [];

    /** @var array<string, ResourceTargetPolicyProvider> */
    private array $targetProviders = [];

    /** @var array<string, ResourceCreatePolicyProvider> */
    private array $createProviders = [];

    public function registerQuery(string $key, ResourceQueryPolicyProvider $provider): void
    {
        $this->queryProviders[$key] = $provider;
    }

    public function registerTarget(string $key, ResourceTargetPolicyProvider $provider): void
    {
        $this->targetProviders[$key] = $provider;
    }

    public function registerCreate(string $key, ResourceCreatePolicyProvider $provider): void
    {
        $this->createProviders[$key] = $provider;
    }

    public function query(string $key): ResourceQueryPolicyProvider
    {
        return $this->queryProviders[$key] ?? throw $this->missing($key);
    }

    public function target(string $key): ResourceTargetPolicyProvider
    {
        return $this->targetProviders[$key] ?? throw $this->missing($key);
    }

    public function create(string $key): ResourceCreatePolicyProvider
    {
        return $this->createProviders[$key] ?? throw $this->missing($key);
    }

    private function missing(string $key): DataAuthorizationException
    {
        return new DataAuthorizationException('AUTHZ_PROVIDER_MISSING', "Resource provider {$key} is not registered.");
    }
}
