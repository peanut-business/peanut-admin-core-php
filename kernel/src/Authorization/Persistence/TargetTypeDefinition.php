<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Persistence;

final readonly class TargetTypeDefinition
{
    public function __construct(
        public string $key,
        public string $moduleKey,
        public string $name,
        public string $resolverKey,
        public string $catalogProviderKey,
        public string $idFormat,
        public string $manifestVersion,
        public string $manifestDigest,
    ) {}
}
