<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Tests\Unit\Definition;

use PeanutAdmin\ReferenceCodes\Application\ReferenceCodeException;
use PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetDefinition;
use PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetLoader;
use PeanutAdmin\ReferenceCodes\Definition\ReferenceCodeSetRegistry;
use PHPUnit\Framework\TestCase;

spl_autoload_register(static function (string $class): void {
    $prefix = 'PeanutAdmin\\ReferenceCodes\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = dirname(__DIR__, 3) . '/src/' . $relative . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

final class ReferenceCodeSetLoaderTest extends TestCase
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

    public function testLoadsOwnedTrimmedDefinitionWithStableCanonicalDigest(): void
    {
        $loaded = (new ReferenceCodeSetLoader())->load('example.owner', $this->json([[
            'description' => '  Neutral values.  ',
            'name' => '  Generic codes  ',
            'key' => 'generic-codes',
        ]]));
        $reordered = (new ReferenceCodeSetLoader())->load('example.owner', $this->json([[
            'key' => 'generic-codes',
            'name' => 'Generic codes',
            'description' => 'Neutral values.',
        ]]));

        self::assertCount(1, $loaded);
        self::assertSame('example.owner', $loaded[0]->moduleKey);
        self::assertSame('Generic codes', $loaded[0]->name);
        self::assertSame('Neutral values.', $loaded[0]->description);
        self::assertSame($loaded[0]->digest, $reordered[0]->digest);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $loaded[0]->digest);
    }

    public function testRejectsMissingResource(): void
    {
        $this->expectError('REFERENCE_CODE_SET_RESOURCE_MISSING', fn() => (new ReferenceCodeSetLoader())->load(
            'example.owner',
            '/definitely/missing/reference-code-sets.json',
        ));
    }

    public function testRejectsInvalidJson(): void
    {
        $this->expectError('REFERENCE_CODE_SET_DEFINITION_INVALID', fn() => (new ReferenceCodeSetLoader())->load(
            'example.owner',
            $this->raw('{'),
        ));
    }

    public function testRejectsNonListRoot(): void
    {
        $this->expectError('REFERENCE_CODE_SET_DEFINITION_INVALID', fn() => (new ReferenceCodeSetLoader())->load(
            'example.owner',
            $this->raw('{"key":"generic-codes"}'),
        ));
    }

    public function testRejectsUnknownField(): void
    {
        $this->expectError('REFERENCE_CODE_SET_DEFINITION_INVALID', fn() => (new ReferenceCodeSetLoader())->load(
            'example.owner',
            $this->json([array_merge($this->definition(), ['owner' => 'other.owner'])]),
        ));
    }

    public function testRejectsMissingField(): void
    {
        $definition = $this->definition();
        unset($definition['description']);
        $this->expectError('REFERENCE_CODE_SET_DEFINITION_INVALID', fn() => (new ReferenceCodeSetLoader())->load(
            'example.owner',
            $this->json([$definition]),
        ));
    }

    public function testRejectsInvalidDeclaringOwner(): void
    {
        $this->expectError('REFERENCE_CODE_SET_OWNER_MISMATCH', fn() => (new ReferenceCodeSetLoader())->load(
            'Invalid Owner',
            $this->json([$this->definition()]),
        ));
    }

    public function testRejectsInvalidLocalKey(): void
    {
        $this->expectError('REFERENCE_CODE_SET_DEFINITION_INVALID', fn() => (new ReferenceCodeSetLoader())->load(
            'example.owner',
            $this->json([$this->definition(['key' => 'Generic_Code'])]),
        ));
    }

    public function testRejectsInvalidDisplayFields(): void
    {
        foreach ([
            ['name' => '   '],
            ['name' => str_repeat('n', 161)],
            ['description' => str_repeat('d', 501)],
        ] as $override) {
            $this->expectError('REFERENCE_CODE_SET_DEFINITION_INVALID', fn() => (new ReferenceCodeSetLoader())->load(
                'example.owner',
                $this->json([$this->definition($override)]),
            ));
        }
        $this->expectError('REFERENCE_CODE_SET_DEFINITION_INVALID', fn() => (new ReferenceCodeSetLoader())->load(
            'example.owner',
            $this->raw("[{\"key\":\"generic-codes\",\"name\":\"Generic codes\",\"description\":\"invalid\xFF\"}]"),
        ));
    }

    public function testRejectsDuplicateLocalKey(): void
    {
        $this->expectError('REFERENCE_CODE_SET_DUPLICATE', fn() => (new ReferenceCodeSetLoader())->load(
            'example.owner',
            $this->json([$this->definition(), $this->definition()]),
        ));
    }

    public function testRegistryRejectsDuplicateOwnerAndOwnerMismatchAtomically(): void
    {
        $definition = (new ReferenceCodeSetLoader())->load(
            'example.owner',
            $this->json([$this->definition()]),
        )[0];
        $registry = new ReferenceCodeSetRegistry();
        $registry->registerModule('example.owner', [$definition]);
        $this->expectError('REFERENCE_CODE_SET_DUPLICATE', fn() => $registry->registerModule(
            'example.owner',
            [],
        ));
        $other = new ReferenceCodeSetRegistry();
        $this->expectError('REFERENCE_CODE_SET_OWNER_MISMATCH', fn() => $other->registerModule(
            'other.owner',
            [$definition],
        ));
        self::assertSame([], $other->all());
    }

    public function testRegistrySortsIdentityAndRestoredDeclarationKeepsDigest(): void
    {
        $loader = new ReferenceCodeSetLoader();
        $original = $loader->load('example.owner', $this->json([$this->definition()]))[0];
        $changed = $loader->load('example.owner', $this->json([
            $this->definition(['name' => 'Changed']),
        ]))[0];
        $restored = $loader->load('example.owner', $this->json([$this->definition()]))[0];
        $registry = new ReferenceCodeSetRegistry();
        $registry->registerModule('example.owner', [
            new ReferenceCodeSetDefinition(
                'example.owner',
                'z-codes',
                'Z codes',
                'Neutral Z values.',
                str_repeat('a', 64),
            ),
            $original,
        ]);

        self::assertNotSame($original->digest, $changed->digest);
        self::assertSame($original->digest, $restored->digest);
        self::assertSame(
            ['example.owner:generic-codes', 'example.owner:z-codes'],
            array_map(static fn(ReferenceCodeSetDefinition $set): string => $set->qualifiedKey(), $registry->all()),
        );
        self::assertSame($original, $registry->require('example.owner', 'generic-codes'));
    }

    /** @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function definition(array $override = []): array
    {
        return array_merge([
            'key' => 'generic-codes',
            'name' => 'Generic codes',
            'description' => 'Neutral values.',
        ], $override);
    }

    /** @param list<array<string, mixed>> $definitions */
    private function json(array $definitions): string
    {
        return $this->raw(json_encode($definitions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function raw(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'peanut-reference-definition-');
        self::assertIsString($file);
        file_put_contents($file, $contents);
        $this->files[] = $file;

        return $file;
    }

    private function expectError(string $code, callable $operation): void
    {
        try {
            $operation();
        } catch (ReferenceCodeException $exception) {
            self::assertSame($code, $exception->errorCode);

            return;
        }
        self::fail("Expected reference-code error {$code}.");
    }
}
