<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Override;

final readonly class ServiceOverride
{
    /**
     * @param class-string $contract
     * @param class-string $implementation
     */
    public function __construct(
        public string $key,
        public string $contract,
        public string $contractVersion,
        public string $implementation,
    ) {}
}
