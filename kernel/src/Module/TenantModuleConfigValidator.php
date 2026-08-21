<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

interface TenantModuleConfigValidator
{
    /** @param array<string, mixed> $config */
    public function assertValid(ManifestDocument $manifest, array $config): void;
}
