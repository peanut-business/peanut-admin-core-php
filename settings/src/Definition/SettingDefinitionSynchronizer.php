<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Definition;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use PeanutAdmin\Settings\Application\SettingException;
use PeanutAdmin\Settings\Model\SettingDefinitionRecord;
use think\db\Raw;
use think\facade\Db;

final readonly class SettingDefinitionSynchronizer
{
    /** @return array{inserted:int,updated:int,retired:int} */
    public function synchronize(SettingDefinitionRegistry $registry, DateTimeImmutable $now): array
    {
        return Db::transaction(function () use ($registry, $now): array {
            $moduleKeys = $registry->moduleKeys();
            if ($moduleKeys === []) {
                return ['inserted' => 0, 'updated' => 0, 'retired' => 0];
            }
            $existing = [];
            foreach (SettingDefinitionRecord::whereIn('module_key', $moduleKeys)->lock(true)->select()->toArray() as $record) {
                $existing[(string) $record['module_key'] . ':' . (string) $record['setting_key']] = $record;
            }
            $declared = [];
            $counts = ['inserted' => 0, 'updated' => 0, 'retired' => 0];
            foreach ($registry->all() as $definition) {
                $key = $definition->qualifiedKey();
                $declared[$key] = true;
                $record = $existing[$key] ?? null;
                if ($record === null) {
                    (new SettingDefinitionRecord())->save($this->values($definition, $now, true));
                    ++$counts['inserted'];
                    continue;
                }
                if (hash_equals((string) $record['definition_digest'], $definition->digest)
                    && $record['status'] === 'active') {
                    continue;
                }
                $values = $this->values($definition, $now, false);
                $values['status'] = 'active';
                $values['revision'] = new Raw('revision + 1');
                SettingDefinitionRecord::where('id', (int) $record['id'])->update($values);
                ++$counts['updated'];
            }
            foreach ($existing as $key => $record) {
                if (isset($declared[$key]) || $record['status'] === 'retired') {
                    continue;
                }
                if (SettingDefinitionRecord::where('id', (int) $record['id'])->update([
                    'status' => 'retired',
                    'revision' => new Raw('revision + 1'),
                    'updated_at' => self::date($now),
                ])) {
                    ++$counts['retired'];
                }
            }

            return $counts;
        });
    }

    /** @return array<string,mixed> */
    private function values(SettingDefinition $definition, DateTimeImmutable $now, bool $identity): array
    {
        $values = [
            'name' => $definition->name,
            'description' => $definition->description,
            'schema_json' => $this->json($definition->schema),
            'required_flag' => (int) $definition->required,
            'secret_flag' => (int) $definition->secret,
            'deployment_scope_flag' => (int) $definition->allows('deployment'),
            'tenant_scope_flag' => (int) $definition->allows('tenant'),
            'target_scope_flag' => (int) $definition->allows('target'),
            'target_resource_key' => $definition->targetResourceKey,
            'target_operation' => $definition->targetOperation,
            'default_json' => $definition->hasDefault ? $this->json($definition->defaultValue) : null,
            'definition_digest' => $definition->digest,
            'updated_at' => self::date($now),
        ];
        if ($identity) {
            $values = [
                'module_key' => $definition->moduleKey,
                'setting_key' => $definition->key,
                ...$values,
                'status' => 'active',
                'revision' => 1,
                'created_at' => self::date($now),
            ];
        }

        return $values;
    }

    private function json(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw SettingException::invalid('SETTING_VALUE_INVALID', 'The setting value cannot be encoded.');
        }
    }

    private static function date(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
