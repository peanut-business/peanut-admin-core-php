<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Definition;

use PeanutAdmin\ReferenceCodes\Application\ReferenceCodeException;

final class ReferenceCodeSetRegistry
{
    /** @var array<string, ReferenceCodeSetDefinition> */
    private array $definitions = [];

    /** @var array<string, true> */
    private array $modules = [];

    /** @param list<ReferenceCodeSetDefinition> $definitions */
    public function registerModule(string $moduleKey, array $definitions): void
    {
        if (isset($this->modules[$moduleKey])) {
            throw ReferenceCodeException::invalid(
                'REFERENCE_CODE_SET_DUPLICATE',
                'A Module cannot register reference-code sets more than once.',
            );
        }
        $validated = [];
        foreach ($definitions as $definition) {
            if ($definition->moduleKey !== $moduleKey) {
                throw ReferenceCodeException::invalid(
                    'REFERENCE_CODE_SET_OWNER_MISMATCH',
                    'The reference-code set owner does not match its declaring Module.',
                );
            }
            $qualifiedKey = $definition->qualifiedKey();
            if (isset($this->definitions[$qualifiedKey]) || isset($validated[$qualifiedKey])) {
                throw ReferenceCodeException::invalid(
                    'REFERENCE_CODE_SET_DUPLICATE',
                    'A reference-code set identity is declared more than once.',
                );
            }
            $validated[$qualifiedKey] = $definition;
        }
        $this->definitions += $validated;
        $this->modules[$moduleKey] = true;
    }

    public function require(string $moduleKey, string $setKey): ReferenceCodeSetDefinition
    {
        return $this->definitions[$moduleKey . ':' . $setKey]
            ?? throw ReferenceCodeException::setNotFound();
    }

    /** @return list<ReferenceCodeSetDefinition> */
    public function all(): array
    {
        $definitions = $this->definitions;
        ksort($definitions, SORT_STRING);

        return array_values($definitions);
    }

    /** @return list<string> */
    public function moduleKeys(): array
    {
        $modules = array_keys($this->modules);
        sort($modules, SORT_STRING);

        return $modules;
    }
}
