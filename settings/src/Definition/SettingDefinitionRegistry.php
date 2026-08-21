<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Definition;

use PeanutAdmin\Settings\Application\SettingException;

final class SettingDefinitionRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    /** @var array<string, true> */
    private array $modules = [];

    /** @param list<SettingDefinition> $definitions */
    public function registerModule(string $moduleKey, array $definitions): void
    {
        if (isset($this->modules[$moduleKey])) {
            throw SettingException::invalid(
                'SETTING_DEFINITION_DUPLICATE',
                'A Module cannot register setting definitions more than once.',
            );
        }
        $validated = [];
        foreach ($definitions as $definition) {
            if ($definition->moduleKey !== $moduleKey) {
                throw SettingException::invalid(
                    'SETTING_DEFINITION_OWNER_MISMATCH',
                    'The setting definition owner does not match its declaring Module.',
                );
            }
            $qualifiedKey = $definition->qualifiedKey();
            if (isset($this->definitions[$qualifiedKey]) || isset($validated[$qualifiedKey])) {
                throw SettingException::invalid(
                    'SETTING_DEFINITION_DUPLICATE',
                    'A setting definition is declared more than once.',
                );
            }
            $validated[$qualifiedKey] = $definition;
        }
        $this->definitions += $validated;
        $this->modules[$moduleKey] = true;
    }

    public function require(string $moduleKey, string $settingKey): SettingDefinition
    {
        return $this->definitions[$moduleKey . ':' . $settingKey]
            ?? throw SettingException::notFound();
    }

    /** @return list<SettingDefinition> */
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
