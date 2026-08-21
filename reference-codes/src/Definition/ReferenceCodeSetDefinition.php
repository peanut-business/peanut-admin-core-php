<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Definition;

final readonly class ReferenceCodeSetDefinition
{
    public function __construct(
        public string $moduleKey,
        public string $key,
        public string $name,
        public string $description,
        public string $digest,
    ) {}

    public function qualifiedKey(): string
    {
        return $this->moduleKey . ':' . $this->key;
    }
}
