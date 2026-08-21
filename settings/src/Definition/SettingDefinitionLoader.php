<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Definition;

use JsonException;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Schemas\ExceptionSchema;
use PeanutAdmin\Kernel\Module\ModuleKey;
use PeanutAdmin\Settings\Application\SettingException;
use Throwable;

final class SettingDefinitionLoader
{
    private const SCOPES = ['deployment', 'tenant', 'target'];

    private const FIELDS = [
        'key', 'name', 'description', 'schema', 'required', 'secret',
        'allowed_scopes', 'target_resource_key', 'target_operation', 'default',
    ];

    /**
     * @param list<array{module_key: string, resource_key: string, operation: string, target_cardinality: string}> $targetDeclarations
     * @return list<SettingDefinition>
     */
    public function load(string $declaringModuleKey, string $resourcePath, array $targetDeclarations = []): array
    {
        try {
            ModuleKey::fromString($declaringModuleKey);
        } catch (Throwable) {
            throw SettingException::invalid('SETTING_DEFINITION_OWNER_MISMATCH', 'The declaring Module key is invalid.');
        }
        if (!is_file($resourcePath) || !is_readable($resourcePath)) {
            throw SettingException::invalid('SETTING_DEFINITION_RESOURCE_MISSING', 'The setting definition resource is missing.');
        }
        try {
            $decoded = json_decode((string) file_get_contents($resourcePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SettingException::invalid('SETTING_DEFINITION_INVALID', 'The setting definition resource is invalid JSON.');
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw SettingException::invalid('SETTING_DEFINITION_INVALID', 'The setting definition resource must be a list.');
        }

        $definitions = [];
        $seen = [];
        foreach ($decoded as $input) {
            if (!is_array($input) || array_is_list($input)) {
                throw SettingException::invalid('SETTING_DEFINITION_INVALID', 'Each setting definition must be an object.');
            }
            $unknown = array_diff(array_keys($input), self::FIELDS);
            $requiredFields = array_diff(array_slice(self::FIELDS, 0, 9), array_keys($input));
            if ($unknown !== [] || $requiredFields !== []) {
                throw SettingException::invalid('SETTING_DEFINITION_INVALID', 'A setting definition has missing or unknown fields.');
            }
            $key = $this->text($input['key'], 64, 'key');
            if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $key) !== 1) {
                throw SettingException::invalid('SETTING_DEFINITION_INVALID', 'The local setting key is invalid.');
            }
            if (isset($seen[$key])) {
                throw SettingException::invalid('SETTING_DEFINITION_DUPLICATE', 'A local setting key is duplicated.');
            }
            $seen[$key] = true;
            $name = $this->text($input['name'], 160, 'name');
            $description = $this->text($input['description'], 500, 'description');
            if (!is_bool($input['required']) || !is_bool($input['secret'])) {
                throw SettingException::invalid('SETTING_DEFINITION_INVALID', 'Setting flags must be boolean.');
            }
            $schema = $input['schema'];
            if (!is_array($schema) || array_is_list($schema)
                || ($schema['$schema'] ?? null) !== 'https://json-schema.org/draft/2020-12/schema'
                || !array_key_exists('type', $schema)) {
                throw SettingException::invalid('SETTING_SCHEMA_UNSUPPORTED', 'Only JSON Schema draft 2020-12 is supported.');
            }
            $this->assertCompilableSchema($schema);
            $scopes = $this->scopes($input['allowed_scopes']);
            $targetResourceKey = $this->nullableText($input['target_resource_key'], 160);
            $targetOperation = $this->nullableText($input['target_operation'], 96);
            $this->assertTargetDeclaration(
                $declaringModuleKey,
                $scopes,
                $targetResourceKey,
                $targetOperation,
                $targetDeclarations,
            );
            $secret = $input['secret'];
            $hasDefault = array_key_exists('default', $input);
            if ($secret && (
                ($schema['type'] ?? null) !== 'string'
                || !is_int($schema['minLength'] ?? null)
                || $schema['minLength'] < 1
                || !is_int($schema['maxLength'] ?? null)
                || $schema['maxLength'] > 4096
                || $schema['maxLength'] < $schema['minLength']
                || $hasDefault
            )) {
                throw SettingException::invalid(
                    'SETTING_SECRET_DEFINITION_INVALID',
                    'Secret definitions require a bounded string schema and cannot declare a default.',
                );
            }
            $normalized = [
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'schema' => $schema,
                'required' => $input['required'],
                'secret' => $secret,
                'allowed_scopes' => $scopes,
                'target_resource_key' => $targetResourceKey,
                'target_operation' => $targetOperation,
            ];
            if ($hasDefault) {
                $normalized['default'] = $input['default'];
            }
            $definition = new SettingDefinition(
                $declaringModuleKey,
                $key,
                $name,
                $description,
                $schema,
                $input['required'],
                $secret,
                $scopes,
                $targetResourceKey,
                $targetOperation,
                $hasDefault,
                $input['default'] ?? null,
                hash('sha256', $this->canonicalJson($normalized)),
            );
            if ($hasDefault) {
                try {
                    $definition->assertValue($input['default']);
                } catch (SettingException) {
                    throw SettingException::invalid('SETTING_DEFAULT_INVALID', 'The setting default does not match its schema.');
                }
            }
            $definitions[] = $definition;
        }

        return $definitions;
    }

    /** @param array<string, mixed> $schema */
    private function assertCompilableSchema(array $schema): void
    {
        try {
            $decoded = json_decode(
                json_encode($schema, JSON_THROW_ON_ERROR),
                false,
                512,
                JSON_THROW_ON_ERROR,
            );
            if (!is_object($decoded)) {
                throw new JsonException('The schema root must be an object.');
            }
            $this->compileSchemaNode($decoded, new SchemaLoader());
        } catch (Throwable) {
            throw SettingException::invalid(
                'SETTING_SCHEMA_UNSUPPORTED',
                'The JSON Schema declaration cannot be compiled as draft 2020-12.',
            );
        }
    }

    private function compileSchemaNode(object|bool $schema, SchemaLoader $loader): void
    {
        $compiled = is_bool($schema)
            ? $loader->loadBooleanSchema($schema, draft: '2020-12')
            : $loader->loadObjectSchema($schema, draft: '2020-12');
        if ($compiled instanceof ExceptionSchema || is_bool($schema)) {
            if ($compiled instanceof ExceptionSchema) {
                throw $this->unsupportedSchema();
            }

            return;
        }

        foreach (['$defs', 'definitions', 'properties', 'patternProperties', 'dependentSchemas'] as $keyword) {
            if (!property_exists($schema, $keyword)) {
                continue;
            }
            $subschemas = $schema->{$keyword};
            if (!is_object($subschemas)) {
                throw $this->unsupportedSchema();
            }
            foreach (get_object_vars($subschemas) as $subschema) {
                $this->compileSchemaValue($subschema, $loader);
            }
        }

        foreach ([
            'additionalItems', 'additionalProperties', 'contains', 'contentSchema', 'else', 'if', 'items',
            'not', 'propertyNames', 'then', 'unevaluatedItems', 'unevaluatedProperties',
        ] as $keyword) {
            if (property_exists($schema, $keyword)) {
                $this->compileSchemaValue($schema->{$keyword}, $loader);
            }
        }

        foreach (['allOf', 'anyOf', 'oneOf', 'prefixItems'] as $keyword) {
            if (!property_exists($schema, $keyword)) {
                continue;
            }
            $subschemas = $schema->{$keyword};
            if (!is_array($subschemas)) {
                throw $this->unsupportedSchema();
            }
            foreach ($subschemas as $subschema) {
                $this->compileSchemaValue($subschema, $loader);
            }
        }

        if (property_exists($schema, 'dependencies')) {
            $dependencies = $schema->dependencies;
            if (!is_object($dependencies)) {
                throw $this->unsupportedSchema();
            }
            foreach (get_object_vars($dependencies) as $dependency) {
                if (is_object($dependency) || is_bool($dependency)) {
                    $this->compileSchemaNode($dependency, $loader);
                }
            }
        }
    }

    private function compileSchemaValue(mixed $schema, SchemaLoader $loader): void
    {
        if (!is_object($schema) && !is_bool($schema)) {
            throw $this->unsupportedSchema();
        }
        $this->compileSchemaNode($schema, $loader);
    }

    private function unsupportedSchema(): SettingException
    {
        return SettingException::invalid(
            'SETTING_SCHEMA_UNSUPPORTED',
            'The JSON Schema declaration cannot be compiled as draft 2020-12.',
        );
    }

    private function text(mixed $value, int $maximum, string $field): string
    {
        if (!is_string($value) || $value === '' || strlen($value) > $maximum) {
            throw SettingException::invalid('SETTING_DEFINITION_INVALID', "The setting {$field} is invalid.");
        }

        return $value;
    }

    private function nullableText(mixed $value, int $maximum): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '' || strlen($value) > $maximum) {
            throw SettingException::invalid('SETTING_TARGET_DECLARATION_INVALID', 'Target metadata is invalid.');
        }

        return $value;
    }

    /** @return non-empty-list<string> */
    private function scopes(mixed $value): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw SettingException::invalid('SETTING_DEFINITION_INVALID', 'Allowed scopes must be a non-empty list.');
        }
        $scopes = [];
        foreach ($value as $scope) {
            if (!is_string($scope) || !in_array($scope, self::SCOPES, true) || isset($scopes[$scope])) {
                throw SettingException::invalid('SETTING_DEFINITION_INVALID', 'Allowed scopes contain an invalid value.');
            }
            $scopes[$scope] = true;
        }
        $result = array_keys($scopes);
        sort($result, SORT_STRING);

        return $result;
    }

    /**
     * @param non-empty-list<string> $scopes
     * @param list<array{module_key: string, resource_key: string, operation: string, target_cardinality: string}> $declarations
     */
    private function assertTargetDeclaration(
        string $moduleKey,
        array $scopes,
        ?string $resourceKey,
        ?string $operation,
        array $declarations,
    ): void {
        if (!in_array('target', $scopes, true)) {
            if ($resourceKey !== null || $operation !== null) {
                throw SettingException::invalid('SETTING_TARGET_DECLARATION_INVALID', 'Target metadata requires target scope.');
            }

            return;
        }
        if ($resourceKey === null || $operation === null) {
            throw SettingException::invalid('SETTING_TARGET_DECLARATION_INVALID', 'Target scope requires trusted operation metadata.');
        }
        foreach ($declarations as $declaration) {
            if ($declaration['module_key'] === $moduleKey
                && $declaration['resource_key'] === $resourceKey
                && $declaration['operation'] === $operation
                && in_array($declaration['target_cardinality'], ['one_required', 'zero_or_one'], true)) {
                return;
            }
        }
        throw SettingException::invalid(
            'SETTING_TARGET_DECLARATION_INVALID',
            'The target setting is not owned by a matching single-target Module operation.',
        );
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        try {
            return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw SettingException::invalid('SETTING_DEFINITION_INVALID', 'The setting definition cannot be canonicalized.');
        }
    }

    /** @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                fn(mixed $item): mixed => is_array($item) ? $this->canonicalize($item) : $item,
                $value,
            );
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalize($item);
            }
        }

        return $value;
    }
}
