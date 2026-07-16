<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

use PeanutAdmin\Kernel\Authorization\Persistence\AuthorizationCatalogRepository;
use PeanutAdmin\Kernel\Authorization\Persistence\PermissionDefinition;

final readonly class CorePermissionCatalogSynchronizer
{
    private const MANIFEST_VERSION = '0.1.0';

    public function __construct(private AuthorizationCatalogRepository $catalog) {}

    public function synchronize(): void
    {
        foreach (CorePermissionCatalog::TENANT as $key) {
            $this->synchronizePermission($key, 'core');
        }
        foreach (CorePermissionCatalog::PLATFORM as $key) {
            $this->synchronizePermission($key, 'platform');
        }
    }

    private function synchronizePermission(string $key, string $moduleKey): void
    {
        $this->catalog->syncPermission(new PermissionDefinition(
            $key,
            $moduleKey,
            'api',
            $key,
            str_contains($key, 'provision-owner') ? 'critical' : 'normal',
            self::MANIFEST_VERSION,
        ));
    }
}
