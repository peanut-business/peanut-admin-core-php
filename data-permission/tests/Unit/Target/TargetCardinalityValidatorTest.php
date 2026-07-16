<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Unit\Target;

use PeanutAdmin\DataPermission\Catalog\ResourceOperation;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PeanutAdmin\DataPermission\Target\TargetCardinalityValidator;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetCollection;
use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TargetCardinalityValidatorTest extends TestCase
{
    /** @return iterable<string, array{string, int, bool}> */
    public static function cases(): iterable
    {
        yield 'none accepts zero' => ['none', 0, true];
        yield 'none rejects one' => ['none', 1, false];
        yield 'one requires one' => ['one_required', 1, true];
        yield 'one rejects zero' => ['one_required', 0, false];
        yield 'one rejects two' => ['one_required', 2, false];
        yield 'optional one accepts zero' => ['zero_or_one', 0, true];
        yield 'optional one accepts one' => ['zero_or_one', 1, true];
        yield 'optional one rejects two' => ['zero_or_one', 2, false];
        yield 'many read accepts zero' => ['many_readable', 0, true];
        yield 'bulk write is disabled' => ['bulk_write', 1, false];
    }

    #[DataProvider('cases')]
    public function testCardinality(string $cardinality, int $targetCount, bool $allowed): void
    {
        $operation = new ResourceOperation(
            1,
            1,
            'example.item',
            'example',
            'provider',
            'business_target_owned',
            'update',
            'explicit_targets',
            $cardinality,
            'all',
            ['example.item.update'],
            [],
        );
        $targets = $targetCount === 0
            ? new TypedResourceTargetCollection()
            : new TypedResourceTargetCollection([
                new TypedResourceTargetSet(
                    'example.project',
                    array_map('strval', range(1, $targetCount)),
                ),
            ]);

        if (!$allowed) {
            $this->expectException(DataAuthorizationException::class);
        }
        (new TargetCardinalityValidator())->validate($operation, $targets);
        if ($allowed) {
            self::addToAssertionCount(1);
        }
    }
}
