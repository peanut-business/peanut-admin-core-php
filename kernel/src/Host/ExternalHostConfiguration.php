<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use InvalidArgumentException;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;

final readonly class ExternalHostConfiguration
{
    /** @var non-empty-list<string> */
    public array $moduleManifestRoots;

    /** @var non-empty-list<string> */
    public array $clientKeys;

    public string $openApiDocument;
    public string $generatedRouteArtifact;
    public string $generatedTypeArtifact;

    /**
     * @param list<string> $moduleManifestRoots
     * @param list<string> $clientKeys
     */
    public function __construct(
        public ModuleHostLayout $moduleLayout,
        array $moduleManifestRoots,
        public string $tenantApiPrefix,
        public string $platformApiPrefix,
        string $openApiDocument,
        string $generatedRouteArtifact,
        string $generatedTypeArtifact,
        array $clientKeys,
        public string $requestIdHeader,
    ) {
        if ($moduleManifestRoots === []) {
            throw new InvalidArgumentException('At least one Module manifest root is required.');
        }
        $this->moduleManifestRoots = array_map($this->relativePath(...), $moduleManifestRoots);
        if (count(array_unique($this->moduleManifestRoots, SORT_STRING)) !== count($this->moduleManifestRoots)) {
            throw new InvalidArgumentException('Module manifest roots must be unique.');
        }

        $this->assertApiPrefix($tenantApiPrefix);
        $this->assertApiPrefix($platformApiPrefix);
        if (
            $tenantApiPrefix === $platformApiPrefix
            || str_starts_with($tenantApiPrefix, $platformApiPrefix . '/')
            || str_starts_with($platformApiPrefix, $tenantApiPrefix . '/')
        ) {
            throw new InvalidArgumentException('Tenant and platform API prefixes must be separate.');
        }

        $this->openApiDocument = $this->relativePath($openApiDocument);
        $this->generatedRouteArtifact = $this->relativePath($generatedRouteArtifact);
        $this->generatedTypeArtifact = $this->relativePath($generatedTypeArtifact);

        if ($clientKeys === []) {
            throw new InvalidArgumentException('At least one Client key is required.');
        }
        foreach ($clientKeys as $clientKey) {
            if (preg_match('/^[a-z][a-z0-9.-]{1,63}$/D', $clientKey) !== 1) {
                throw new InvalidArgumentException('Invalid Client key.');
            }
        }
        if (count(array_unique($clientKeys, SORT_STRING)) !== count($clientKeys)) {
            throw new InvalidArgumentException('Client keys must be unique.');
        }
        $this->clientKeys = $clientKeys;

        if (preg_match('/^[A-Za-z][A-Za-z0-9-]{0,63}$/D', $requestIdHeader) !== 1) {
            throw new InvalidArgumentException('Invalid request ID header.');
        }
    }

    public function acceptsClientKey(string $clientKey): bool
    {
        return in_array($clientKey, $this->clientKeys, true);
    }

    public function assertOperation(ExternalOperationDefinition $operation): void
    {
        $prefix = $operation->audience === 'tenant' ? $this->tenantApiPrefix : $this->platformApiPrefix;
        if ($operation->path !== $prefix && !str_starts_with($operation->path, $prefix . '/')) {
            throw new InvalidArgumentException('Operation path is outside its configured audience prefix.');
        }
    }

    private function assertApiPrefix(string $prefix): void
    {
        if (
            $prefix === '/'
            || str_ends_with($prefix, '/')
            || preg_match('#^/[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+)*$#D', $prefix) !== 1
        ) {
            throw new InvalidArgumentException('Invalid API prefix.');
        }
    }

    private function relativePath(string $path): string
    {
        $path = rtrim($path, '/');
        if (
            $path === ''
            || str_starts_with($path, '/')
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $path) === 1
            || preg_match('#^[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*$#D', $path) !== 1
        ) {
            throw new InvalidArgumentException('Invalid host-relative path.');
        }

        return $path;
    }
}
