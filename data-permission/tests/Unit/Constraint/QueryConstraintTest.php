<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Tests\Unit\Constraint;

use InvalidArgumentException;
use PDO;
use PeanutAdmin\App\Tests\Support\ThinkPhpTestConnection;
use PeanutAdmin\DataPermission\Constraint\AndConstraint;
use PeanutAdmin\DataPermission\Constraint\ColumnEquals;
use PeanutAdmin\DataPermission\Constraint\ColumnIn;
use PeanutAdmin\DataPermission\Constraint\ColumnReference;
use PeanutAdmin\DataPermission\Constraint\ExistsByContract;
use PeanutAdmin\DataPermission\Constraint\JsonArrayContainsColumn;
use PeanutAdmin\DataPermission\Constraint\QueryConstraint;
use PeanutAdmin\DataPermission\Constraint\TenantEquals;
use PeanutAdmin\DataPermission\Constraint\ThinkPhpQueryConstraintApplier;
use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;
use PHPUnit\Framework\TestCase;
use think\db\BaseQuery;
use think\db\Query;
use think\facade\Db;

final class QueryConstraintTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ThinkPhpTestConnection::fromPdo(new PDO('sqlite::memory:'));
    }

    public function testApplierProducesOnlyBoundStructuredQueryConditions(): void
    {
        $constraint = new AndConstraint([
            new TenantEquals(new ColumnReference('item.tenant_id'), 42),
            new ColumnEquals(new ColumnReference('item.owner_id'), 7),
            new ColumnIn(new ColumnReference('item.project_id'), ['A', 'B']),
        ]);
        $query = $this->applied($constraint);
        $binds = $query->getBind(false);
        $sql = (string) (clone $query)->fetchSql()->select();

        self::assertStringContainsString('item.tenant_id', $sql);
        self::assertStringContainsString('item.project_id', $sql);
        self::assertCount(4, $binds);
    }

    public function testLargeTargetSetsUseTheFixedExistsContract(): void
    {
        $query = $this->applied(new ExistsByContract(
            'data_permission.target-set',
            new ColumnReference('item.project_id'),
            42,
            99,
        ));

        $binds = $query->getBind(false);
        $sql = (string) (clone $query)->fetchSql()->select();
        self::assertStringContainsString('EXISTS (', $sql);
        self::assertStringContainsString('pa_data_permission_target', $sql);
        self::assertCount(2, $binds);
    }

    public function testLargeRequestedTargetSetUsesOneJsonParameter(): void
    {
        $query = $this->applied(new JsonArrayContainsColumn(
            new ColumnReference('item.project_id'),
            array_map('strval', range(1, 5000)),
        ));

        $binds = array_values($query->getBind(false));
        $sql = (string) (clone $query)->fetchSql()->select();
        self::assertStringContainsString('JSON_TABLE(', $sql);
        self::assertStringContainsString('CAST(item.project_id AS CHAR', $sql);
        self::assertCount(1, $binds);
        $json = is_array($binds[0]) ? $binds[0][0] : $binds[0];
        self::assertCount(5000, json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR));
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
        (new ThinkPhpQueryConstraintApplier())->apply(
            Db::table('item')->alias('item'),
            new class implements QueryConstraint {},
        );
    }

    private function applied(QueryConstraint $constraint): Query
    {
        $query = new Query(Db::connect());
        $query->table('item');
        $query->alias('item');
        (new ThinkPhpQueryConstraintApplier())->apply($query, $constraint);

        return $query;
    }
}
