<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Tests\Unit\Definition;

use PeanutAdmin\Settings\Application\SettingException;
use PeanutAdmin\Settings\Definition\SettingDefinition;
use PeanutAdmin\Settings\Definition\SettingDefinitionLoader;
use PeanutAdmin\Settings\Definition\SettingDefinitionRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SettingDefinitionLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function testLoadsOwnedDefinitionsWithStableCanonicalDigest(): void
    {
        $first = $this->definition([
            'allowed_scopes' => ['tenant', 'deployment'],
            'default' => false,
        ]);
        $second = [
            'target_operation' => null,
            'target_resource_key' => null,
            'allowed_scopes' => ['tenant', 'deployment'],
            'secret' => false,
            'required' => false,
            'schema' => $first['schema'],
            'description' => 'Controls a generic capability.',
            'name' => 'Capability enabled',
            'key' => 'capability-enabled',
            'default' => false,
        ];

        $loaded = (new SettingDefinitionLoader())->load('example.module', $this->json([$first]));
        $reordered = (new SettingDefinitionLoader())->load('example.module', $this->json([$second]));

        self::assertCount(1, $loaded);
        self::assertSame('example.module', $loaded[0]->moduleKey);
        self::assertSame('capability-enabled', $loaded[0]->key);
        self::assertSame(['deployment', 'tenant'], $loaded[0]->allowedScopes);
        self::assertTrue($loaded[0]->hasDefault);
        self::assertFalse($loaded[0]->defaultValue);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $loaded[0]->digest);
        self::assertSame($loaded[0]->digest, $reordered[0]->digest);
    }

    public function testValidatesTargetDeclarationOwnedByTheSameModule(): void
    {
        $definition = $this->definition([
            'allowed_scopes' => ['target'],
            'target_resource_key' => 'example.project',
            'target_operation' => 'updateProjectSetting',
        ]);
        $declarations = [[
            'module_key' => 'example.module',
            'resource_key' => 'example.project',
            'operation' => 'updateProjectSetting',
            'target_cardinality' => 'one_required',
        ]];

        $loaded = (new SettingDefinitionLoader())->load(
            'example.module',
            $this->json([$definition]),
            $declarations,
        );

        self::assertSame('example.project', $loaded[0]->targetResourceKey);
        self::assertSame('updateProjectSetting', $loaded[0]->targetOperation);
    }

    /** @param list<array<string, mixed>> $definitions */
    #[DataProvider('invalidDefinitionProvider')]
    public function testRejectsMalformedOrUnsafeDefinitions(array $definitions, string $code): void
    {
        $this->expectSettingError($code, fn(): array => (new SettingDefinitionLoader())->load(
            'example.module',
            $this->json($definitions),
        ));
    }

    /** @return iterable<string, array{list<array<string, mixed>>, string}> */
    public static function invalidDefinitionProvider(): iterable
    {
        $base = self::baseDefinition();

        yield 'duplicate key' => [[$base, $base], 'SETTING_DEFINITION_DUPLICATE'];
        yield 'unknown field' => [[array_merge($base, ['owner' => 'other.module'])], 'SETTING_DEFINITION_INVALID'];
        yield 'invalid slug' => [[array_merge($base, ['key' => 'Capability.Enabled'])], 'SETTING_DEFINITION_INVALID'];
        yield 'unsupported draft' => [[array_replace_recursive($base, [
            'schema' => ['$schema' => 'http://json-schema.org/draft-07/schema#'],
        ])], 'SETTING_SCHEMA_UNSUPPORTED'];
        yield 'invalid schema keyword without default' => [[array_merge($base, [
            'schema' => [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'string',
                'minLength' => 'invalid',
            ],
        ])], 'SETTING_SCHEMA_UNSUPPORTED'];
        yield 'invalid nested schema without default' => [[array_merge($base, [
            'schema' => [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 'invalid'],
                ],
            ],
        ])], 'SETTING_SCHEMA_UNSUPPORTED'];
        yield 'duplicate scope' => [[array_merge($base, [
            'allowed_scopes' => ['tenant', 'tenant'],
        ])], 'SETTING_DEFINITION_INVALID'];
        yield 'target metadata without scope' => [[array_merge($base, [
            'target_resource_key' => 'example.project',
            'target_operation' => 'updateProjectSetting',
        ])], 'SETTING_TARGET_DECLARATION_INVALID'];
        yield 'secret default' => [[array_merge($base, [
            'schema' => [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'string',
                'minLength' => 1,
                'maxLength' => 128,
            ],
            'secret' => true,
            'default' => 'not-allowed',
        ])], 'SETTING_SECRET_DEFINITION_INVALID'];
        yield 'secret without bounded string schema' => [[array_merge($base, [
            'schema' => [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'string',
                'minLength' => 0,
                'maxLength' => 5000,
            ],
            'secret' => true,
        ])], 'SETTING_SECRET_DEFINITION_INVALID'];
        yield 'schema-invalid default' => [[array_merge($base, ['default' => 'yes'])], 'SETTING_DEFAULT_INVALID'];
    }

    public function testRejectsTargetDeclarationsFromAnotherOwnerOrWithoutSingleTargetCardinality(): void
    {
        $definition = $this->definition([
            'allowed_scopes' => ['target'],
            'target_resource_key' => 'example.project',
            'target_operation' => 'updateProjectSetting',
        ]);
        foreach ([
            [[
                'module_key' => 'other.module',
                'resource_key' => 'example.project',
                'operation' => 'updateProjectSetting',
                'target_cardinality' => 'one_required',
            ]],
            [[
                'module_key' => 'example.module',
                'resource_key' => 'example.project',
                'operation' => 'updateProjectSetting',
                'target_cardinality' => 'many_readable',
            ]],
        ] as $declarations) {
            $this->expectSettingError(
                'SETTING_TARGET_DECLARATION_INVALID',
                fn(): array => (new SettingDefinitionLoader())->load(
                    'example.module',
                    $this->json([$definition]),
                    $declarations,
                ),
            );
        }
    }

    public function testRegistryRejectsOwnerMismatchAndDuplicatesAndSortsDefinitions(): void
    {
        $loader = new SettingDefinitionLoader();
        $alpha = $loader->load('example.alpha', $this->json([
            $this->definition(['key' => 'z-setting']),
            $this->definition(['key' => 'a-setting']),
        ]));
        $registry = new SettingDefinitionRegistry();
        $registry->registerModule('example.alpha', $alpha);

        self::assertSame(
            ['example.alpha:a-setting', 'example.alpha:z-setting'],
            array_map(
                static fn(SettingDefinition $definition): string => $definition->qualifiedKey(),
                $registry->all(),
            ),
        );
        self::assertSame('a-setting', $registry->require('example.alpha', 'a-setting')->key);

        $nonOverlapping = $loader->load('example.alpha', $this->json([
            $this->definition(['key' => 'different-setting']),
        ]));
        $this->expectSettingError(
            'SETTING_DEFINITION_DUPLICATE',
            fn() => $registry->registerModule('example.alpha', $nonOverlapping),
        );

        $this->expectSettingError(
            'SETTING_DEFINITION_DUPLICATE',
            fn() => $registry->registerModule('example.alpha', [$alpha[0]]),
        );
        $this->expectSettingError(
            'SETTING_DEFINITION_OWNER_MISMATCH',
            fn() => (new SettingDefinitionRegistry())->registerModule('other.module', [$alpha[0]]),
        );
    }

    public function testRegistryValidationFailuresDoNotPartiallyRegisterDefinitions(): void
    {
        $loader = new SettingDefinitionLoader();
        $beta = $loader->load('example.beta', $this->json([
            $this->definition(['key' => 'beta-setting']),
        ]));
        $foreign = $loader->load('example.alpha', $this->json([
            $this->definition(['key' => 'foreign-setting']),
        ]));
        $ownerMismatchRegistry = new SettingDefinitionRegistry();

        $this->expectSettingError(
            'SETTING_DEFINITION_OWNER_MISMATCH',
            fn() => $ownerMismatchRegistry->registerModule('example.beta', [$beta[0], $foreign[0]]),
        );
        self::assertSame([], $ownerMismatchRegistry->moduleKeys());
        self::assertSame([], $ownerMismatchRegistry->all());
        $ownerMismatchRegistry->registerModule('example.beta', $beta);
        self::assertSame(['example.beta'], $ownerMismatchRegistry->moduleKeys());

        $duplicateRegistry = new SettingDefinitionRegistry();
        $this->expectSettingError(
            'SETTING_DEFINITION_DUPLICATE',
            fn() => $duplicateRegistry->registerModule('example.beta', [$beta[0], $beta[0]]),
        );
        self::assertSame([], $duplicateRegistry->moduleKeys());
        self::assertSame([], $duplicateRegistry->all());
        $duplicateRegistry->registerModule('example.beta', $beta);
        self::assertSame(['example.beta:beta-setting'], array_map(
            static fn(SettingDefinition $definition): string => $definition->qualifiedKey(),
            $duplicateRegistry->all(),
        ));
    }

    /** @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function definition(array $override = []): array
    {
        return array_merge(self::baseDefinition(), $override);
    }

    /** @return array<string, mixed> */
    private static function baseDefinition(): array
    {
        return [
            'key' => 'capability-enabled',
            'name' => 'Capability enabled',
            'description' => 'Controls a generic capability.',
            'schema' => [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'boolean',
            ],
            'required' => false,
            'secret' => false,
            'allowed_scopes' => ['tenant'],
            'target_resource_key' => null,
            'target_operation' => null,
        ];
    }

    /** @param list<array<string, mixed>> $definitions */
    private function json(array $definitions): string
    {
        $file = tempnam(sys_get_temp_dir(), 'peanut-settings-definition-');
        self::assertIsString($file);
        file_put_contents($file, json_encode($definitions, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        $this->files[] = $file;

        return $file;
    }

    private function expectSettingError(string $code, callable $operation): void
    {
        try {
            $operation();
        } catch (SettingException $exception) {
            self::assertSame($code, $exception->errorCode);

            return;
        }

        self::fail("Expected settings error {$code}.");
    }
}
