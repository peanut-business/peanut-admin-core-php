<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Unit\Constraint;

use InvalidArgumentException;
use PeanutAdmin\DataPermission\Constraint\AndConstraint;
use PeanutAdmin\DataPermission\Constraint\ColumnEquals;
use PeanutAdmin\DataPermission\Constraint\ColumnIn;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Constraint\ExistsByContract;
use PeanutAdmin\DataPermission\Constraint\JsonArrayContainsColumn;
use PeanutAdmin\DataPermission\Constraint\PdoQueryConstraintCompiler;
use PeanutAdmin\DataPermission\Constraint\QueryConstraint;
use PeanutAdmin\DataPermission\Constraint\TenantEquals;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PHPUnit\Framework\TestCase;

final class QueryConstraintTest extends TestCase
{
    public function testCompilerProducesOnlyParameterizedStructuredSql(): void
    {
        $constraint = new AndConstraint([
            new TenantEquals(new ColumnReference('item.tenant_id'), 42),
            new ColumnEquals(new ColumnReference('item.owner_id'), 7),
            new ColumnIn(new ColumnReference('item.project_id'), ['A', 'B']),
        ]);
        $compiled = (new PdoQueryConstraintCompiler())->compile($constraint);

        self::assertStringContainsString('item.tenant_id = :authz_1', $compiled->sql);
        self::assertStringContainsString('item.project_id IN (:authz_3, :authz_4)', $compiled->sql);
        self::assertSame([42, 7, 'A', 'B'], array_values($compiled->parameters));
    }

    public function testLargeTargetSetsUseTheFixedExistsContract(): void
    {
        $compiled = (new PdoQueryConstraintCompiler())->compile(new ExistsByContract(
            'data_permission.target-set',
            new ColumnReference('item.project_id'),
            42,
            99,
        ));

        self::assertStringContainsString('EXISTS (', $compiled->sql);
        self::assertStringContainsString('pa_data_permission_target', $compiled->sql);
        self::assertCount(2, $compiled->parameters);
    }

    public function testLargeRequestedTargetSetUsesOneJsonParameter(): void
    {
        $compiled = (new PdoQueryConstraintCompiler())->compile(new JsonArrayContainsColumn(
            new ColumnReference('item.project_id'),
            array_map('strval', range(1, 5000)),
        ));

        self::assertStringContainsString('JSON_TABLE(', $compiled->sql);
        self::assertStringContainsString('CAST(item.project_id AS CHAR', $compiled->sql);
        self::assertCount(1, $compiled->parameters);
        self::assertCount(5000, json_decode((string) array_values($compiled->parameters)[0], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testColumnInRejectsMoreThanFiveHundredValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ColumnIn(new ColumnReference('item.id'), range(1, 501));
    }

    public function testColumnReferenceRejectsTenantControlledSqlFragments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ColumnReference('item.id OR 1=1');
    }

    public function testUnknownConstraintTypesFailClosed(): void
    {
        $this->expectException(DataAuthorizationException::class);
        (new PdoQueryConstraintCompiler())->compile(new class implements QueryConstraint {});
    }
}
