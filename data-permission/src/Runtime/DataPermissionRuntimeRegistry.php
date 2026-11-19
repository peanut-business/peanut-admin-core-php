<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Runtime;

use PeanutAdmin\DataPermission\Provider\ResourcePolicyProvider;
use PeanutAdmin\DataPermission\Provider\ResourceProviderRegistry;
use PeanutAdmin\DataPermission\Provider\SharedMasterScopeProvider;
use PeanutAdmin\DataPermission\Provider\SharedMasterScopeProviderRegistry;
use PeanutAdmin\DataPermission\Target\ResourceTargetCatalogProvider;
use PeanutAdmin\DataPermission\Target\ResourceTargetResolver;
use PeanutAdmin\DataPermission\Target\TargetCatalogProviderRegistry;
use PeanutAdmin\DataPermission\Target\TargetResolverRegistry;

final readonly class DataPermissionRuntimeRegistry
{
    public function __construct(
        public ResourceProviderRegistry $resourceProviders = new ResourceProviderRegistry(),
        public TargetResolverRegistry $targetResolvers = new TargetResolverRegistry(),
        public TargetCatalogProviderRegistry $targetCatalogProviders = new TargetCatalogProviderRegistry(),
        public SharedMasterScopeProviderRegistry $sharedMasterProviders = new SharedMasterScopeProviderRegistry(),
    ) {}

    public function registerResourceProvider(string $key, ResourcePolicyProvider $provider): void
    {
        $this->resourceProviders->register($key, $provider);
    }

    public function registerTargetResolver(string $key, ResourceTargetResolver $resolver): void
    {
        $this->targetResolvers->register($key, $resolver);
    }

    public function registerTargetCatalogProvider(string $key, ResourceTargetCatalogProvider $provider): void
    {
        $this->targetCatalogProviders->register($key, $provider);
    }

    public function registerSharedMasterProvider(string $resourceKey, SharedMasterScopeProvider $provider): void
    {
        $this->sharedMasterProviders->register($resourceKey, $provider);
    }
}
