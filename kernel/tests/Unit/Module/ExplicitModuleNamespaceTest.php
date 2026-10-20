<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Module;

use InvalidArgumentException;
use PeanutAdmin\Kernel\Module\CompiledModuleRegistry;
use PeanutAdmin\Kernel\Module\ManifestDocument;
use PeanutAdmin\Kernel\Module\ModuleBoundaryChecker;
use PeanutAdmin\Kernel\Module\ModuleException;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;
use PeanutAdmin\Kernel\Module\ModuleKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Package names may differ from host paths without weakening dependency/export checks. */
final class ExplicitModuleNamespaceTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->temporaryFiles) as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        foreach (array_reverse($this->temporaryDirectories) as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testExplicitNamespacesPreserveKeyDerivedPathsAndLegacyFallback(): void
    {
        $layout = $this->layout();
        $key = ModuleKey::fromString('demo.catalog');
        self::assertSame('backend/modules/demo/catalog/', $layout->backendRelativePath($key));
        self::assertSame('Vendor\\Packages\\Catalog\\', $layout->backendNamespace($key));
        self::assertTrue($layout->hasExplicitBackendNamespace($key));
        self::assertSame('frontend/modules/demo-catalog/', $layout->frontendRelativePath($key));
        $other = ModuleKey::fromString('demo.unlisted');
        self::assertSame('Host\\Modules\\demo\\unlisted\\', $layout->backendNamespace($other));
        self::assertFalse($layout->hasExplicitBackendNamespace($other));
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidNamespaces(): iterable
    {
        yield 'invalid key' => [['not a module' => 'Vendor\\Catalog']];
        yield 'invalid namespace' => [['demo.catalog' => 'Vendor/../Catalog']];
        yield 'not a string' => [['demo.catalog' => ['Vendor\\Catalog']]];
        yield 'case collision' => [[
            'demo.catalog' => 'Vendor\\Catalog', 'demo.billing' => 'vendor\\catalog',
        ]];
        yield 'nested namespace collision' => [[
            'demo.catalog' => 'Vendor\\Catalog', 'demo.billing' => 'Vendor\\Catalog\\Internal',
        ]];
    }

    #[DataProvider('invalidNamespaces')]
    public function testInvalidNamespaceMapsFailClosed(array $namespaces): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ModuleHostLayout('backend/modules', 'Host\\Modules', 'frontend/modules', $namespaces);
    }

    public function testExplicitPublicServiceOutsideLegacyContractsDirectoryIsAccepted(): void
    {
        $this->boundary(
            'use Vendor\\Packages\\Catalog\\Service\\ProductQuery;',
            [['module_key' => 'demo.catalog']],
            ['Vendor\\Packages\\Catalog\\Service\\ProductQuery'],
        )->check();
        self::assertTrue(true);
    }

    public function testRegisteredExternalNamespaceCannotBypassDependencyCheck(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('without declaring dependency');
        $this->boundary(
            'use Vendor\\Packages\\Catalog\\Service\\ProductQuery;',
            [],
            ['Vendor\\Packages\\Catalog\\Service\\ProductQuery'],
        )->check();
    }

    public function testRegisteredExternalNamespaceCannotBypassExportCheck(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('does not export');
        $this->boundary(
            'use Vendor\\Packages\\Catalog\\Internal\\ProductRecord;',
            [['module_key' => 'demo.catalog']],
            ['Vendor\\Packages\\Catalog\\Service\\ProductQuery'],
        )->check();
    }

    public function testUnknownNamespaceWithinHostRootRemainsRejected(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('outside its registered contracts API');
        $this->boundary('use Host\\Modules\\unknown\\module\\Hidden;', [], [])->check();
    }

    public function testLegacyHostsKeepTheirExistingContractsPathRule(): void
    {
        $layout = new ModuleHostLayout('backend/modules', 'Host\\Modules', 'frontend/modules');
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('outside its registered contracts API');
        $this->boundary(
            'use Host\\Modules\\demo\\catalog\\service\\Query;',
            [['module_key' => 'demo.catalog']],
            ['Host\\Modules\\demo\\catalog\\service\\Query'],
            $layout,
        )->check();
    }

    public function testUnknownExternalLibrariesAreNotReclassifiedAsBusinessModules(): void
    {
        $this->boundary('use ThirdParty\\Clock\\ClockInterface;', [], [])->check();
        self::assertTrue(true);
    }

    private function layout(): ModuleHostLayout
    {
        return new ModuleHostLayout('backend/modules', 'Host\\Modules', 'frontend/modules', [
            'demo.catalog' => 'Vendor\\Packages\\Catalog',
            'demo.billing' => 'Vendor\\Packages\\Billing',
        ]);
    }

    public function testSelectedOptionalStillRequiresAnExportedContract(): void
    {
        $optional = [['module_key' => 'demo.catalog', 'version' => '^1.0']];
        $this->boundary('use Vendor\\Packages\\Catalog\\Service\\ProductQuery;', [],
            ['Vendor\\Packages\\Catalog\\Service\\ProductQuery'], optionalDependencies: $optional)->check();
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('does not export');
        $this->boundary('use Vendor\\Packages\\Catalog\\Internal\\ProductRecord;', [],
            ['Vendor\\Packages\\Catalog\\Service\\ProductQuery'], optionalDependencies: $optional)->check();
    }

    public function testAbsentOptionalTypesCanColdLoadWithoutLoadingTheProvider(): void
    {
        $name = 'ColdLoad' . bin2hex(random_bytes(8));
        $checker = $this->boundary(
            'namespace Vendor\\Packages\\Billing; use Vendor\\Packages\\Catalog\\Service\\ProductQuery; '
                . 'final class ' . $name . ' { public function query(?ProductQuery $query): ?ProductQuery { return $query; } }',
            [], [], optionalDependencies: [['module_key' => 'demo.catalog', 'version' => '^1.0']], selectCatalog: false,
        );
        $checker->check();
        require $this->temporaryFiles[array_key_last($this->temporaryFiles)];
        $class = 'Vendor\\Packages\\Billing\\' . $name;
        self::assertTrue(class_exists($class, false));
        self::assertFalse(class_exists('Vendor\\Packages\\Catalog\\Service\\ProductQuery', false));
        self::assertSame('Vendor\\Packages\\Catalog\\Service\\ProductQuery',
            (new \ReflectionMethod($class, 'query'))->getReturnType()?->getName());
        self::assertNull((new $class())->query(null));
    }

    public function testAbsentOptionalDeclarationDoesNotGrantOtherModulesOrTables(): void
    {
        $optional = [['module_key' => 'demo.catalog', 'version' => '^1.0']];
        foreach (['use Host\\Modules\\unknown\\module\\Hidden;', '$table = "demo_catalog";',
            '$sql = "REFERENCES `demo_catalog`";'] as $reference) {
            try {
                $this->boundary($reference, [], [], optionalDependencies: $optional, selectCatalog: false)->check();
                self::fail('Absent optional declarations must not grant other modules or tables.');
            } catch (ModuleException $exception) {
                self::assertSame('MODULE_REGISTRY_CONFLICT', $exception->errorCode);
            }
        }
    }

    public function testSelectedOptionalDoesNotGrantPrivateTableAccess(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage('references table demo_catalog');
        $this->boundary('$table = "demo_catalog";', [], [],
            optionalDependencies: [['module_key' => 'demo.catalog', 'version' => '^1.0']])->check();
    }

    private function boundary(
        string $reference,
        array $dependencies,
        array $exports,
        ?ModuleHostLayout $layout = null,
        array $optionalDependencies = [],
        bool $selectCatalog = true,
    ): ModuleBoundaryChecker {
        $root = sys_get_temp_dir() . '/module-namespace-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $this->temporaryDirectories[] = $root;
        foreach (['catalog', 'billing'] as $name) {
            mkdir($root . '/' . $name, 0700);
            $this->temporaryDirectories[] = $root . '/' . $name;
        }
        $path = $root . '/billing/UseCase.php';
        file_put_contents($path, "<?php\n" . $reference . "\n");
        $this->temporaryFiles[] = $path;
        $catalog = ManifestDocument::fromArray($root . '/catalog', [
            'key' => 'demo.catalog', 'dependencies' => [], 'contracts' => ['exports' => $exports],
        ]);
        $billing = ManifestDocument::fromArray($root . '/billing', [
            'key' => 'demo.billing', 'dependencies' => $dependencies, 'contracts' => ['exports' => []],
            'optional_dependencies' => $optionalDependencies,
        ]);
        return new ModuleBoundaryChecker(
            new CompiledModuleRegistry($selectCatalog ? [$catalog, $billing] : [$billing], [],
                $selectCatalog ? ['demo_catalog' => 'demo.catalog'] : [], [], 'fixture-revision'),
            $layout ?? $this->layout(),
            ['demo_'],
        );
    }
}
