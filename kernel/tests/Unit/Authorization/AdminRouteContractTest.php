<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Authorization;

use PeanutAdmin\Kernel\Authorization\Application\AdminAccessException;
use PeanutAdmin\Kernel\Authorization\Application\Etag;
use PHPUnit\Framework\TestCase;

final class AdminRouteContractTest extends TestCase
{
    public function testEtagParserRequiresTheExactRevisionFormat(): void
    {
        self::assertSame(12, Etag::parse('"rev-12"'));

        try {
            Etag::parse(null);
            self::fail('Missing If-Match must fail.');
        } catch (AdminAccessException $exception) {
            self::assertSame(428, $exception->httpStatus);
        }

        $this->expectException(AdminAccessException::class);
        Etag::parse('rev-12');
    }
}
