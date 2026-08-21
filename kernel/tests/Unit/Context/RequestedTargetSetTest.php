<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Context;

use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PHPUnit\Framework\TestCase;

final class RequestedTargetSetTest extends TestCase
{
    public function testNumericLookingIdentifiersRemainStringsAfterNormalization(): void
    {
        $targets = new RequestedTargetSet('example.project', ['10', '2', '2'], 'destination');

        self::assertSame(['10', '2'], $targets->targetIds);
        self::assertSame('destination', $targets->targetRole);
        self::assertSame('destination', $targets->toArray()['target_role']);
    }
}
