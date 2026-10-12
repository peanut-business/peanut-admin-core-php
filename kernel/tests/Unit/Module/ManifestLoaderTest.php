<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Module;

use Opis\JsonSchema\Validator;
use PeanutAdmin\Kernel\Module\ManifestLoader;
use PHPUnit\Framework\TestCase;

final class ManifestLoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/peanut-manifest-loader-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/module.json');
        @rmdir($this->root);
    }

    public function testBackendOnlyManifestPreservesEmptyFrontendObjectForSchemaValidation(): void
    {
        $document = $this->loadWithFrontend('{}');

        self::assertIsObject($document->object->frontend);
        self::assertTrue($this->schemaResult($document->object));
    }

    public function testFrontendArrayRemainsAnArrayAndFailsTheObjectSchema(): void
    {
        $document = $this->loadWithFrontend('[]');

        self::assertIsArray($document->object->frontend);
        self::assertFalse($this->schemaResult($document->object));
    }

    public function testOptionalDependenciesUseTheRequiredDependencySchemaAndSurviveLoading(): void
    {
        $this->loadWithFrontend('{}');
        $data = json_decode((string) file_get_contents($this->root . '/module.json'), true, 512, JSON_THROW_ON_ERROR);
        $data['frontend'] = new \stdClass();
        $data['optional_dependencies'] = [['module_key' => 'official.task', 'version' => '^1.0']];
        file_put_contents($this->root . '/module.json', json_encode($data, JSON_THROW_ON_ERROR));
        $document = (new ManifestLoader())->load($this->root);
        self::assertSame($data['optional_dependencies'], $document->data['optional_dependencies']);
        self::assertTrue($this->schemaResult($document->object));
        foreach ([
            [['module_key' => 'official.task']],
            [['module_key' => 'bad module', 'version' => '*']],
            [['module_key' => 'official.task', 'version' => '']],
            [['module_key' => 'official.task', 'version' => '*', 'install' => true]],
            (object) ['module_key' => 'official.task', 'version' => '*'],
        ] as $invalid) {
            $document->object->optional_dependencies = $invalid;
            self::assertFalse($this->schemaResult($document->object));
        }
    }

    private function loadWithFrontend(string $frontend): object
    {
        $json = <<<'JSON'
{
  "schema_version": 1,
  "key": "official.backend-only",
  "name": "Backend only",
  "description": "Backend-only fixture",
  "version": "1.0.0",
  "kernel_constraint": "^1.0",
  "license": "Apache-2.0",
  "dependencies": [],
  "backend": {"provider": "Fixture\\BackendOnly\\ModuleProvider"},
  "frontend": __FRONTEND__,
  "database": {"owned_tables": []},
  "contracts": {"exports": [], "events": []},
  "tenant": {"enableable": true, "requires": []}
}
JSON;
        file_put_contents($this->root . '/module.json', str_replace('__FRONTEND__', $frontend, $json));

        return (new ManifestLoader())->load($this->root);
    }

    private function schemaResult(object $manifest): bool
    {
        $schema = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/resources/schemas/module-manifest.schema.json'),
        );

        return is_object($schema) && (new Validator())->validate($manifest, $schema)->isValid();
    }
}
