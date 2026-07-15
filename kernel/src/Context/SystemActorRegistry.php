<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Context;

final readonly class SystemActorRegistry
{
    /** @var array<string, SystemActorDefinition> */
    private array $definitions;

    /** @param list<SystemActorDefinition> $definitions */
    public function __construct(array $definitions)
    {
        $indexed = [];
        foreach ($definitions as $definition) {
            $indexed[$definition->key] = $definition;
        }
        $this->definitions = $indexed;
    }

    public function definition(string $key): ?SystemActorDefinition
    {
        return $this->definitions[$key] ?? null;
    }
}
