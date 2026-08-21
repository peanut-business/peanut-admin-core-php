<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Runtime;

use PeanutAdmin\DataPermission\Provider\ResourceCreatePolicyProvider;
use PeanutAdmin\DataPermission\Provider\ResourceProviderRegistry;
use PeanutAdmin\DataPermission\Provider\ResourceQueryPolicyProvider;
use PeanutAdmin\DataPermission\Provider\ResourceTargetPolicyProvider;
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

    public function registerResourceProvider(
        string $key,
        ResourceQueryPolicyProvider&ResourceTargetPolicyProvider&ResourceCreatePolicyProvider $provider,
    ): void {
        $this->resourceProviders->registerQuery($key, $provider);
        $this->resourceProviders->registerTarget($key, $provider);
        $this->resourceProviders->registerCreate($key, $provider);
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
