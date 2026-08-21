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
}
