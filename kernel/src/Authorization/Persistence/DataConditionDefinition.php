<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization\Persistence;

final readonly class DataConditionDefinition
{
    /** @param array<string, mixed>|null $configSchema */
    public function __construct(
        public string $key,
        public string $moduleKey,
        public string $category,
        public string $targetMode,
        public ?array $configSchema,
        public string $manifestVersion,
        public string $manifestDigest,
    ) {}
}
