<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Tests\Unit\Media;

use PeanutAdmin\FileMedia\Media\QrCodeGenerator;
use PHPUnit\Framework\TestCase;

final class OptionalMediaTest extends TestCase
{
    public function testSvgQrCodeDoesNotRequireGd(): void
    {
        self::assertStringContainsString('<svg', (new QrCodeGenerator())->svg('example'));
    }
}
