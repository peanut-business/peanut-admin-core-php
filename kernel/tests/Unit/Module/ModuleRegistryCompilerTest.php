<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Module;

use PeanutAdmin\Kernel\Module\ContractInspector;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ManifestSchemaValidator;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleRegistryCompiler;
use PeanutAdmin\Kernel\Module\VersionConstraintMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleRegistryCompilerTest extends TestCase
{
    public function testCompilerBuildsOneDeterministicRegistryInDependencyOrder(): void
    {
        $compiler = $this->compiler();

        $registry = $compiler->compile([
            $this->manifest('example.work-item', ['example.target']),
            $this->manifest('example.target'),
        ]);

        self::assertSame(['example.target', 'example.work-item'], $registry->moduleKeys());
        self::assertSame(
            hash('sha256', implode('|', array_map(
                static fn(ManifestDocument $document): string => $document->digest,
                [$this->manifest('example.target'), $this->manifest('example.work-item', ['example.target'])],
            ))),
            $registry->revision,
        );
    }

    /** @param list<ManifestDocument> $documents */
    #[DataProvider('invalidGraphCases')]
    public function testCompilerRejectsInvalidDependencyGraphs(
        array $documents,
        string $expectedCode,
    ): void {
        try {
            $this->compiler()->compile($documents);
        } catch (ModuleException $exception) {
            self::assertSame($expectedCode, $exception->errorCode);

            return;
        }

        self::fail('Expected module registry compilation to fail.');
    }

    /** @return iterable<string, array{list<ManifestDocument>, string}> */
    public static function invalidGraphCases(): iterable
    {
        $test = new self('testCompilerRejectsInvalidDependencyGraphs');

        yield 'missing dependency' => [
            [$test->manifest('example.work-item', ['example.missing'])],
            'MODULE_DEPENDENCY_MISSING',
        ];
        yield 'cycle' => [
            [
                $test->manifest('example.one', ['example.two']),
                $test->manifest('example.two', ['example.one']),
            ],
            'MODULE_DEPENDENCY_CYCLE',
        ];
        yield 'version mismatch' => [
            [
                $test->manifest('example.target', version: '2.0.0'),
                $test->manifest('example.work-item', ['example.target']),
            ],
            'MODULE_VERSION_INCOMPATIBLE',
        ];
    }

    public function testCompilerRejectsDuplicateTargetOwnershipAndMissingProviders(): void
    {
        $target = [[
            'key' => 'example.project',
            'name' => 'Project',
            'resolver' => 'resolver.project',
            'catalog_provider' => 'catalog.project',
        ]];

        $this->expectModuleCode('MODULE_REGISTRY_CONFLICT', function () use ($target): void {
            $this->compiler()->compile([
                $this->manifest('example.one', targetTypes: $target),
                $this->manifest('example.two', targetTypes: $target),
            ]);
        });

        $this->expectModuleCode('MODULE_CONTRACT_MISSING', function (): void {
            $this->compiler(['provider.module'])->compile([
                $this->manifest('example.target', targetTypes: [[
                    'key' => 'example.project',
                    'name' => 'Project',
                    'resolver' => 'resolver.missing',
                    'catalog_provider' => 'catalog.project',
                ]]),
            ]);
        });
    }

    public function testCompilerRequiresCardinalityTargetDeclarationsAndSharedScopeProvider(): void
    {
        $this->expectModuleCode('MODULE_REGISTRY_CONFLICT', function (): void {
            $this->compiler()->compile([
                $this->manifest('example.work-item', protectedResources: [[
                    'key' => 'example.work-item',
                    'ownership' => 'business_target_owned',
                    'provider' => 'query.work-item',
                    'operations' => [['key' => 'list']],
                ]]),
            ]);
        });

        $this->expectModuleCode('MODULE_CONTRACT_MISSING', function (): void {
            $this->compiler()->compile([
                $this->manifest('example.reference', protectedResources: [[
                    'key' => 'example.reference',
                    'ownership' => 'shared_master',
                    'provider' => 'query.reference',
                    'scope_provider' => null,
                    'operations' => [[
                        'key' => 'list',
                        'target_cardinality' => 'many_readable',
                        'target_types' => [],
                    ]],
                ]]),
            ]);
        });
    }

    public function testCompilerRejectsUnknownFrontendComponentAndProviderNamespace(): void
    {
        $this->expectModuleCode('MODULE_CONTRACT_MISSING', function (): void {
            $this->compiler(components: [])->compile([
                $this->manifest('example.target', menus: [[
                    'key' => 'example.target.list',
                    'scope' => 'tenant',
                    'type' => 'page',
                    'component_key' => 'example.target.list',
                    'required_permission' => 'example.target.read',
                ]]),
            ]);
        });

        $manifest = $this->manifest('example.target');
        $data = $manifest->data;
        $data['backend']['provider'] = 'PeanutAdmin\\App\\Modules\\Wrong\\Provider';
        $this->expectModuleCode('MODULE_CONTRACT_MISSING', function () use ($data): void {
            $this->compiler()->compile([ManifestDocument::fromArray('/tmp/example-target', $data)]);
        });
    }

    /**
     * @param list<string> $dependencies
     * @param list<array<string, mixed>> $targetTypes
     * @param list<array<string, mixed>> $protectedResources
     * @param list<array<string, mixed>> $menus
     */
    private function manifest(
        string $key,
        array $dependencies = [],
        string $version = '1.0.0',
        array $targetTypes = [],
        array $protectedResources = [],
        array $menus = [],
    ): ManifestDocument {
        $moduleKey = \PeanutAdmin\Kernel\Module\ModuleKey::fromString($key);

        return ManifestDocument::fromArray('/tmp/' . str_replace('.', '-', $key), [
            'schema_version' => 1,
            'key' => $key,
            'name' => $key,
            'description' => 'Test module',
            'version' => $version,
            'kernel_constraint' => '^1.0',
            'license' => 'Apache-2.0',
            'dependencies' => array_map(
                static fn(string $dependency): array => [
                    'module_key' => $dependency,
                    'version' => '^1.0',
                ],
                $dependencies,
            ),
            'backend' => [
                'provider' => $moduleKey->backendNamespace() . 'ModuleProvider',
            ],
            'frontend' => [],
            'database' => ['owned_tables' => []],
            'contracts' => ['exports' => [], 'events' => []],
            'tenant' => ['enableable' => true, 'requires' => $dependencies],
            'catalog' => [
                'target_types' => $targetTypes,
                'protected_resources' => $protectedResources,
                'menus' => $menus,
            ],
        ]);
    }

    /**
     * @param list<string>|null $availableContracts
     * @param list<string> $components
     */
    private function compiler(
        ?array $availableContracts = null,
        array $components = ['example.target.list'],
    ): ModuleRegistryCompiler {
        $available = $availableContracts ?? [
            'provider.module',
            'resolver.project',
            'catalog.project',
            'query.work-item',
            'query.reference',
            'scope.reference',
        ];

        return new ModuleRegistryCompiler(
            new class implements ManifestSchemaValidator {
                public function assertValid(object $manifest): void {}
            },
            new class implements VersionConstraintMatcher {
                public function matches(string $version, string $constraint): bool
                {
                    return str_starts_with($constraint, '^1.') && str_starts_with($version, '1.');
                }
            },
            new class ($available) implements ContractInspector {
                /** @param list<string> $available */
                public function __construct(private array $available) {}

                public function classExists(string $class): bool
                {
                    return str_ends_with($class, 'ModuleProvider');
                }

                public function implements(string $class, string $contract): bool
                {
                    if ($contract === \PeanutAdmin\Kernel\Module\ModuleProvider::class) {
                        return str_ends_with($class, 'ModuleProvider');
                    }

                    return in_array($class, $this->available, true);
                }
            },
            '1.0.0',
            $components,
        );
    }

    private function expectModuleCode(string $code, callable $operation): void
    {
        try {
            $operation();
        } catch (ModuleException $exception) {
            self::assertSame($code, $exception->errorCode);

            return;
        }

        self::fail("Expected {$code}.");
    }
}
