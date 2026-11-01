<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Module;

use Opis\JsonSchema\Validator;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ManifestSchemaValidator;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;
use PeanutAdmin\Kernel\Module\ModuleRegistryCompiler;
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
        yield 'selected optional version mismatch' => [
            [
                $test->manifest('example.target', version: '2.0.0'),
                $test->manifest('example.work-item', optionalDependencies: ['example.target']),
            ],
            'MODULE_VERSION_INCOMPATIBLE',
        ];
        yield 'selected optional cycle' => [
            [
                $test->manifest('example.one', optionalDependencies: ['example.two']),
                $test->manifest('example.two', ['example.one']),
            ],
            'MODULE_DEPENDENCY_CYCLE',
        ];
        yield 'required cannot become optional' => [
            [$test->manifest('example.one', ['example.missing'], optionalDependencies: ['example.missing'])],
            'MODULE_DEPENDENCY_MISSING',
        ];
    }

    public function testOptionalDependenciesOnlyParticipateWhenExplicitlySelected(): void
    {
        // The optional key sorts after the consumer, so this proves an actual graph edge.
        $consumer = $this->manifest('example.alpha', optionalDependencies: ['example.target']);
        self::assertSame(['example.alpha'], $this->compiler()->compile([$consumer])->moduleKeys());
        $registry = $this->compiler()->compile([$consumer, $this->manifest('example.target')]);
        self::assertSame(['example.target', 'example.alpha'], $registry->moduleKeys());
        self::assertSame([], $consumer->data['dependencies']);
        self::assertSame([], $consumer->data['tenant']['requires']);
        self::assertSame($registry->revision, $this->compiler()->compile([
            $this->manifest('example.target'), $consumer,
        ])->revision);
    }

    public function testSelectedOptionalOwnerCanSupplyAPermissionWithoutTenantEnablement(): void
    {
        $registry = $this->compiler()->compile([
            $this->manifest('example.work-item', optionalDependencies: ['example.target'], menus: [[
                'key' => 'example.work-item.page', 'type' => 'page',
                'component_key' => 'example.target.list', 'required_permission' => 'example.target.read',
            ]]),
            $this->manifest('example.target', permissions: [$this->permission('example.target.read')]),
        ]);
        self::assertSame(['example.target', 'example.work-item'], $registry->moduleKeys());
    }

    public function testCompilerRejectsDuplicateTargetOwnershipAndMissingProviders(): void
    {
        $target = [[
            'key' => 'example.project',
            'name' => 'Project',
            'resolver' => 'ProjectResolver',
            'catalog_provider' => 'ProjectCatalogProvider',
        ]];

        $this->expectModuleCode('MODULE_REGISTRY_CONFLICT', function () use ($target): void {
            $this->compiler()->compile([
                $this->manifest('example.one', targetTypes: $target),
                $this->manifest('example.two', targetTypes: $target),
            ]);
        });

        $this->expectModuleCode('MODULE_CONTRACT_MISSING', function (): void {
            $this->compiler()->compile([
                $this->manifest('example.target', targetTypes: [[
                    'key' => 'example.project',
                    'name' => 'Project',
                    'resolver' => 'MissingProjectResolver',
                    'catalog_provider' => 'ProjectCatalogProvider',
                ]]),
            ]);
        });
    }

    public function testCompilerRequiresCardinalityTargetDeclarationsAndSharedScopeProvider(): void
    {
        // Structure is owned by the manifest schema, before Compiler's semantic checks.
        $this->assertInvalidOperation(['key' => 'list']);

        $this->expectModuleCode('MODULE_REGISTRY_CONFLICT', function (): void {
            $this->compiler()->compile([
                $this->manifest('example.work-item', protectedResources: [[
                    'key' => 'example.work-item',
                    'ownership' => 'business_target_owned',
                    'provider' => 'WorkItemQueryProvider',
                    'operations' => [['key' => 'list', 'target_cardinality' => 'one_required']],
                ]]),
            ]);
        });

        $this->expectModuleCode('MODULE_CONTRACT_MISSING', function (): void {
            $this->compiler()->compile([
                $this->manifest('example.reference', protectedResources: [[
                    'key' => 'example.reference',
                    'ownership' => 'shared_master',
                    'provider' => 'ReferenceQueryProvider',
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

    public function testCompilerRequiresStructuredOperationTargetRelations(): void
    {
        $target = [[
            'key' => 'example.project',
            'name' => 'Project',
            'resolver' => 'ProjectResolver',
            'catalog_provider' => 'ProjectCatalogProvider',
        ]];
        $resource = static fn(array $targetTypes): array => [
            'key' => 'example.work-item',
            'ownership' => 'business_target_owned',
            'provider' => 'WorkItemQueryProvider',
            'operations' => [[
                'key' => 'transfer',
                'target_cardinality' => 'one_required',
                'target_types' => $targetTypes,
            ]],
        ];

        $this->assertInvalidOperation($resource(['example.project'])['operations'][0]);

        $registry = $this->compiler()->compile([
            $this->manifest(
                'example.target',
                targetTypes: $target,
                permissions: [$this->permission('example.target.manage')],
            ),
            $this->manifest(
                'example.work-item',
                ['example.target'],
                protectedResources: [$resource([[
                    'target_resource_key' => 'example.project',
                    'target_role' => 'destination',
                    'input_mode' => 'explicit',
                    'policy_selection_permission' => 'example.target.manage',
                ]])],
            ),
        ]);

        self::assertSame(['example.target', 'example.work-item'], $registry->moduleKeys());
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
        $data['backend']['provider'] = 'Example\\Modules\\Wrong\\Provider';
        $this->expectModuleCode('MODULE_CONTRACT_MISSING', function () use ($data): void {
            $this->compiler()->compile([ManifestDocument::fromObject('/tmp/example-target', (object) $data)]);
        });
    }

    public function testCompilerAcceptsExternalHostNamespaceAndBusinessTablePrefix(): void
    {
        $manifest = $this->manifest('dcs.store', ownedTables: ['dcs_store']);

        $registry = $this->compiler(
            layout: new ModuleHostLayout('backend/app/Modules', 'Dcs\\Modules', 'frontend/src/modules', [
                'dcs.store' => 'Dcs\\Modules\\Store',
            ]),
        )->compile([$manifest]);

        self::assertSame(['dcs_store' => 'dcs.store'], $registry->ownedTableOwners);
    }

    public function testCompilerRejectsMenusForUnregisteredClients(): void
    {
        $this->expectModuleCode('MODULE_REGISTRY_CONFLICT', function (): void {
            $this->compiler(registeredClientKeys: ['admin-web'])->compile([
                $this->manifest(
                    'example.target',
                    menus: [[
                        'key' => 'example.target.group',
                        'scope' => 'tenant',
                        'type' => 'group',
                        'client_keys' => ['unknown-web'],
                    ]],
                ),
            ]);
        });
    }

    public function testCompilerRejectsReservedOrUnsafeTableNames(): void
    {
        $this->expectModuleCode('MODULE_REGISTRY_CONFLICT', function (): void {
            $this->compiler(reservedTables: ['pa_tenant'])->compile([
                $this->manifest('example.target', ownedTables: ['pa_tenant']),
            ]);
        });

        $this->expectModuleCode('MODULE_MANIFEST_INVALID', function (): void {
            $this->compiler()->compile([
                $this->manifest('example.target', ownedTables: ['unsafe-table']),
            ]);
        });
    }

    public function testCompilerRejectsUnknownCatalogReferencesAndUndeclaredTargetOwner(): void
    {
        $resource = static fn(array $overrides = []): array => $overrides + [
            'key' => 'example.work-item',
            'ownership' => 'tenant_owned',
            'provider' => 'WorkItemQueryProvider',
            'operations' => [[
                'key' => 'list',
                'permissions' => ['example.missing.read'],
                'target_cardinality' => 'none',
                'target_types' => [],
                'conditions' => [],
            ]],
        ];

        $this->expectModuleCode('MODULE_REGISTRY_CONFLICT', function () use ($resource): void {
            $this->compiler()->compile([
                $this->manifest('example.work-item', protectedResources: [$resource()]),
            ]);
        });

        $target = [[
            'key' => 'example.project',
            'name' => 'Project',
            'resolver' => 'ProjectResolver',
            'catalog_provider' => 'ProjectCatalogProvider',
        ]];
        $targeted = $resource(['operations' => [[
            'key' => 'list',
            'permissions' => ['core.member.read'],
            'target_cardinality' => 'many_readable',
            'target_types' => [[
                'target_resource_key' => 'example.project',
                'target_role' => 'primary',
                'input_mode' => 'explicit',
                'policy_selection_permission' => null,
            ]],
            'conditions' => [],
        ]]]);
        $this->expectModuleCode('MODULE_DEPENDENCY_MISSING', function () use ($target, $targeted): void {
            $this->compiler()->compile([
                $this->manifest('example.target', targetTypes: $target),
                $this->manifest('example.work-item', protectedResources: [$targeted]),
            ]);
        });
    }

    public function testHistoricalTableCanOnlyBeClaimedOnceByItsExplicitOwner(): void
    {
        $compiler = $this->compiler(
            reservedTables: ['pa_tenant'],
            reservedTableOwners: ['pa_tenant' => 'example.target'],
        );
        self::assertSame(['example.target'], $compiler->compile([
            $this->manifest('example.target', ownedTables: ['pa_tenant']),
        ])->moduleKeys());
        $this->expectModuleCode('MODULE_REGISTRY_CONFLICT', function () use ($compiler): void {
            $compiler->compile([$this->manifest('example.other', ownedTables: ['pa_tenant'])]);
        });
        $this->expectModuleCode('MODULE_REGISTRY_CONFLICT', function () use ($compiler): void {
            $compiler->compile([$this->manifest('example.target', ownedTables: ['pa_tenant', 'pa_tenant'])]);
        });
    }

    public function testCompilerRejectsResourceProviderOwnedByAnotherModule(): void
    {
        $this->expectModuleCode('MODULE_CONTRACT_MISSING', function (): void {
            $this->compiler()->compile([
                $this->manifest('example.work-item', protectedResources: [[
                    'key' => 'example.work-item',
                    'ownership' => 'tenant_owned',
                    'provider' => 'Example\\Modules\\Other\\ForeignPolicyProvider',
                    'operations' => [['key' => 'list', 'target_cardinality' => 'none']],
                ]]),
            ]);
        });
    }

    /**
     * @param list<string> $dependencies
     * @param list<array<string, mixed>> $targetTypes
     * @param list<array<string, mixed>> $protectedResources
     * @param list<array<string, mixed>> $menus
     * @param list<array<string, mixed>> $permissions
     * @param list<string> $ownedTables
     */
    private function manifest(
        string $key,
        array $dependencies = [],
        string $version = '1.0.0',
        array $targetTypes = [],
        array $protectedResources = [],
        array $menus = [],
        array $permissions = [],
        array $ownedTables = [],
        array $optionalDependencies = [],
    ): ManifestDocument {
        $moduleKey = \PeanutAdmin\Kernel\Module\ModuleKey::fromString($key);
        $layout = str_starts_with($key, 'dcs.')
            ? new ModuleHostLayout('backend/app/Modules', 'Dcs\\Modules', 'frontend/src/modules', [
                'dcs.store' => 'Dcs\\Modules\\Store',
            ])
            : $this->referenceLayout();
        $namespace = $layout->backendNamespace($moduleKey);
        foreach ($targetTypes as &$targetType) {
            foreach (['resolver', 'catalog_provider'] as $field) {
                if (is_string($targetType[$field] ?? null) && !str_contains($targetType[$field], '\\')) {
                    $targetType[$field] = $namespace . $targetType[$field];
                }
            }
        }
        unset($targetType);
        foreach ($protectedResources as &$resource) {
            foreach (['provider', 'scope_provider'] as $field) {
                if (is_string($resource[$field] ?? null) && !str_contains($resource[$field], '\\')) {
                    $resource[$field] = $namespace . $resource[$field];
                }
            }
            foreach ($resource['operations'] as &$operation) {
                $operation += ['permissions' => ['core.member.read'], 'target_types' => [], 'conditions' => []];
            }
            unset($operation);
        }
        unset($resource);

        return ManifestDocument::fromObject('/tmp/' . str_replace('.', '-', $key), (object) [
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
                'provider' => $namespace . 'ModuleProvider',
            ],
            'optional_dependencies' => array_map(
                static fn(string $dependency): array => ['module_key' => $dependency, 'version' => '^1.0'],
                $optionalDependencies,
            ),
            'frontend' => [],
            'database' => ['owned_tables' => $ownedTables],
            'contracts' => ['exports' => [], 'events' => []],
            'tenant' => ['enableable' => true, 'requires' => $dependencies],
            'catalog' => [
                'target_types' => $targetTypes,
                'protected_resources' => $protectedResources,
                'menus' => $menus,
                'permissions' => $permissions,
            ],
        ]);
    }

    /**
     * @param list<string> $components
     * @param list<string> $reservedTables
     * @param non-empty-list<string> $registeredClientKeys
     */
    private function compiler(
        array $components = ['example.target.list'],
        ?ModuleHostLayout $layout = null,
        array $reservedTables = [],
        array $registeredClientKeys = ['admin-web', 'platform-web'],
        array $reservedTableOwners = [],
    ): ModuleRegistryCompiler {
        return new ModuleRegistryCompiler(
            new class implements ManifestSchemaValidator {
                public function assertValid(object $manifest): void {}
            },
            '1.0.0',
            $components,
            $layout ?? $this->referenceLayout(),
            $reservedTables,
            $registeredClientKeys,
            ['core.member.read'],
            $reservedTableOwners,
        );
    }

    private function referenceLayout(): ModuleHostLayout
    {
        return new ModuleHostLayout(
            'backend/app/modules',
            'Example\\Modules',
            'frontend/src/modules',
            [
                'example.alpha' => 'Example\\Modules\\Alpha',
                'example.one' => 'Example\\Modules\\One',
                'example.other' => 'Example\\Modules\\Other',
                'example.reference' => 'Example\\Modules\\Reference',
                'example.target' => 'Example\\Modules\\Target',
                'example.two' => 'Example\\Modules\\Two',
                'example.work-item' => 'Example\\Modules\\WorkItem',
            ],
        );
    }

    /** @param array<string, mixed> $operation */
    private function assertInvalidOperation(array $operation): void
    {
        $operation += [
            'access_mode' => 'rule_filtered',
            'permission_match' => 'all',
            'audit_level' => 'deny',
            'permissions' => ['core.member.read'],
            'target_types' => [],
            'conditions' => [],
        ];
        $schema = json_decode((string) file_get_contents(
            dirname(__DIR__, 3) . '/resources/schemas/module-manifest.schema.json',
        ), false, 512, JSON_THROW_ON_ERROR);
        $operationSchema = $schema->properties->catalog->properties->protected_resources
            ->items->properties->operations->items;
        $object = json_decode(json_encode($operation, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        self::assertFalse((new Validator())->validate($object, $operationSchema)->isValid());
    }

    /** @return array{key: string, type: string, name: string, risk_level: string} */
    private function permission(string $key): array
    {
        return ['key' => $key, 'type' => 'api', 'name' => $key, 'risk_level' => 'normal'];
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

// Compiler checks types and module ownership without constructing provider instances.
namespace Example\Modules\Alpha;

abstract class ModuleProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider {}

namespace Example\Modules\One;

abstract class ModuleProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider {}
abstract class ProjectResolver implements \PeanutAdmin\DataPermission\Target\ResourceTargetResolver {}
abstract class ProjectCatalogProvider implements \PeanutAdmin\DataPermission\Target\ResourceTargetCatalogProvider {}

namespace Example\Modules\Other;

abstract class ModuleProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider {}
abstract class ForeignPolicyProvider implements \PeanutAdmin\DataPermission\Provider\ResourceQueryPolicyProvider {}

namespace Example\Modules\Reference;

abstract class ModuleProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider {}
abstract class ReferenceQueryProvider implements \PeanutAdmin\DataPermission\Provider\ResourceQueryPolicyProvider {}

namespace Example\Modules\Target;

abstract class ModuleProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider {}
abstract class ProjectResolver implements \PeanutAdmin\DataPermission\Target\ResourceTargetResolver {}
abstract class ProjectCatalogProvider implements \PeanutAdmin\DataPermission\Target\ResourceTargetCatalogProvider {}

namespace Example\Modules\Two;

abstract class ModuleProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider {}
abstract class ProjectResolver implements \PeanutAdmin\DataPermission\Target\ResourceTargetResolver {}
abstract class ProjectCatalogProvider implements \PeanutAdmin\DataPermission\Target\ResourceTargetCatalogProvider {}

namespace Example\Modules\WorkItem;

abstract class ModuleProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider {}
abstract class WorkItemQueryProvider implements \PeanutAdmin\DataPermission\Provider\ResourceQueryPolicyProvider {}

namespace Dcs\Modules\Store;

abstract class ModuleProvider implements \PeanutAdmin\Kernel\Module\ModuleProvider {}
