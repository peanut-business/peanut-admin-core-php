<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Host;

use InvalidArgumentException;
use PeanutAdmin\Kernel\Authorization\PermissionRequirement;
use PeanutAdmin\Kernel\Host\ExternalHostConfiguration;
use PeanutAdmin\Kernel\Host\ExternalOperationDefinition;
use PeanutAdmin\Kernel\Module\ModuleHostLayout;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalHostConfigurationTest extends TestCase
{
    public function testHostOwnedLayoutPrefixesArtifactsAndClientsAreRetained(): void
    {
        $layout = new ModuleHostLayout('backend/app/Modules', 'FixtureHost\\App\\Modules', 'frontend/src/modules');
        $configuration = new ExternalHostConfiguration(
            $layout,
            ['backend/app/Modules/Fixture/Record'],
            '/tenant/api/v1',
            '/platform/api/v1',
            'docs/api/openapi.yaml',
            'backend/route/openapi-generated.php',
            'packages/web/generated/api.d.ts',
            ['fixture-web'],
            'X-Request-ID',
        );

        self::assertSame($layout, $configuration->moduleLayout);
        self::assertSame(['backend/app/Modules/Fixture/Record'], $configuration->moduleManifestRoots);
        self::assertTrue($configuration->acceptsClientKey('fixture-web'));
        self::assertFalse($configuration->acceptsClientKey('other-web'));

        $configuration->assertOperation(new ExternalOperationDefinition(
            'fixtureRecordsList',
            'GET',
            '/tenant/api/v1/fixture/records',
            'tenant',
            'fixture.record',
            new PermissionRequirement('tenant', ['fixture.record.read']),
            'fixture.record',
            'query',
            'many_readable',
        ));
    }

    /** @param array<int, mixed> $arguments */
    #[DataProvider('invalidConfigurations')]
    public function testInvalidHostOwnedConfigurationFailsClosed(array $arguments): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ExternalHostConfiguration(...$arguments);
    }

    /** @return iterable<string, array{array<int, mixed>}> */
    public static function invalidConfigurations(): iterable
    {
        $layout = new ModuleHostLayout('backend/app/Modules', 'FixtureHost\\App\\Modules', 'frontend/src/modules');
        $valid = [
            $layout,
            ['backend/app/Modules/Fixture/Record'],
            '/tenant/api/v1',
            '/platform/api/v1',
            'docs/api/openapi.yaml',
            'backend/route/openapi-generated.php',
            'packages/web/generated/api.d.ts',
            ['fixture-web'],
            'X-Request-ID',
        ];

        $cases = [
            'empty manifest roots' => [1, []],
            'path traversal' => [4, '../openapi.yaml'],
            'equal prefixes' => [3, '/tenant/api/v1'],
            'nested prefixes' => [3, '/tenant/api/v1/platform'],
            'invalid client key' => [7, ['Fixture Web']],
            'duplicate client key' => [7, ['fixture-web', 'fixture-web']],
            'invalid request header' => [8, "X Request\nID"],
        ];
        foreach ($cases as $name => [$index, $value]) {
            $arguments = $valid;
            $arguments[$index] = $value;
            yield $name => [$arguments];
        }
    }
}
