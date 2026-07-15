<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Persistence;

final readonly class ProtectedResourceDefinition
{
    public function __construct(
        public string $key,
        public string $moduleKey,
        public string $name,
        public string $ownership,
        public string $providerKey,
        public string $manifestVersion,
        public string $manifestDigest,
    ) {}
}
