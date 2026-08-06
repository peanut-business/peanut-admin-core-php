<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Schema;

use PHPUnit\Framework\TestCase;

final class MigrationInventoryTest extends TestCase
{
    public function testKernelSchemaHasTheFrozenMigrationCount(): void
    {
        $files = glob(dirname(__DIR__, 3) . '/database/migrations/*.php');

        self::assertIsArray($files);
        self::assertCount(40, $files);
    }
}
