<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Migration;

use PeanutAdmin\Kernel\Migration\MigrationRecord;
use PeanutAdmin\Kernel\Migration\ModuleMigrationLedger;
use PeanutAdmin\Kernel\Module\ModuleException;
use PHPUnit\Framework\TestCase;

final class ModuleMigrationLedgerTest extends TestCase
{
    public function testAppliedMigrationIsSkippedOnlyWhenChecksumMatches(): void
    {
        $ledger = new ModuleMigrationLedger([
            new MigrationRecord('example.target', 'example.target:001', '1.0.0', hash('sha256', 'original'), 'applied'),
        ]);

        self::assertFalse($ledger->shouldApply('example.target', 'example.target:001', hash('sha256', 'original')));

        try {
            $ledger->shouldApply('example.target', 'example.target:001', hash('sha256', 'changed'));
        } catch (ModuleException $exception) {
            self::assertSame('MODULE_MIGRATION_CHECKSUM_MISMATCH', $exception->errorCode);

            return;
        }

        self::fail('Changed migration must fail closed.');
    }
}
