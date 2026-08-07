<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Override;

final readonly class ServiceOverrideSlot
{
    /**
     * @param class-string $contract
     * @param class-string $defaultImplementation
     */
    public function __construct(
        public string $key,
        public string $contract,
        public string $contractVersion,
        public string $defaultImplementation,
    ) {}
}
