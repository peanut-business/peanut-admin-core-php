<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

final readonly class CompiledModuleRegistry
{
    /**
     * @param list<ManifestDocument> $modules
     * @param array<string, string> $targetTypeOwners
     * @param array<string, string> $ownedTableOwners
     * @param array<string, array<string, mixed>> $menus
     */
    public function __construct(
        public array $modules,
        public array $targetTypeOwners,
        public array $ownedTableOwners,
        public array $menus,
        public string $revision,
    ) {}

    /** @return list<string> */
    public function moduleKeys(): array
    {
        return array_map(
            static fn(ManifestDocument $document): string => (string) $document->data['key'],
            $this->modules,
        );
    }

    public function requireManifest(string $moduleKey): ManifestDocument
    {
        foreach ($this->modules as $manifest) {
            if (($manifest->data['key'] ?? null) === $moduleKey) {
                return $manifest;
            }
        }

        throw new ModuleException('MODULE_NOT_INSTALLED', "Unknown module: {$moduleKey}");
    }

    /** Required foundations are deployment-managed and never have per-Tenant switches. */
    public function isRequiredTenantFoundation(string $moduleKey): bool
    {
        $manifest = $this->requireManifest($moduleKey);

        return ($manifest->data['lifecycle']['protected'] ?? false) === true
            && ($manifest->data['tenant']['enableable'] ?? null) === false;
    }
}
