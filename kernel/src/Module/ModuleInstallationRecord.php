<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

final readonly class ModuleInstallationRecord
{
    public function __construct(
        public string $moduleKey,
        public string $installedVersion,
        public string $status,
        public int $revision,
        public string $manifestDigest,
    ) {}
}
