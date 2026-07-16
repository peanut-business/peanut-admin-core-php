<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Unit\Target;

use PeanutAdmin\DataPermission\Target\TypedResourceTargetSet;
use PHPUnit\Framework\TestCase;

final class TypedResourceTargetSetTest extends TestCase
{
    public function testNumericLookingIdentifiersRemainStringsWhenDeduplicated(): void
    {
        $targets = new TypedResourceTargetSet('example.project', ['2', '10', '2']);

        self::assertSame(['2', '10'], $targets->targetIds);
    }
}
