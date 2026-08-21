<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Module;

use InvalidArgumentException;
use PeanutAdmin\Kernel\Module\ModuleKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ModuleKeyTest extends TestCase
{
    public function testModuleKeyOnlyNormalizesItsMechanicalSegments(): void
    {
        $key = ModuleKey::fromString('example.work-item');

        self::assertSame('example.work-item', $key->value());
        self::assertSame(['Example', 'WorkItem'], $key->pascalSegments());
        self::assertSame('example-work-item', $key->slug());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidKeys(): iterable
    {
        yield 'uppercase' => ['Example.item'];
        yield 'underscore' => ['example.work_item'];
        yield 'empty segment' => ['example..item'];
        yield 'leading number' => ['1example.item'];
        yield 'path traversal' => ['example.../item'];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeysFailClosed(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        ModuleKey::fromString($value);
    }
}
