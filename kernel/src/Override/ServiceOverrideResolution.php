<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Override;

final readonly class ServiceOverrideResolution
{
    /**
     * @param class-string $contract
     * @param class-string $implementation
     * @param 'default'|'application' $source
     */
    public function __construct(
        public string $key,
        public string $contract,
        public string $contractVersion,
        public string $implementation,
        public string $source,
    ) {}
}
