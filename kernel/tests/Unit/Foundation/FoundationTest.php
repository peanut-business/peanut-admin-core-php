<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Foundation;

use PeanutAdmin\Kernel\State\StateMachine;
use PeanutAdmin\Kernel\Query\DeclarativeQueryBuilder;
use PeanutAdmin\Kernel\Authorization\Application\PageRequest;
use think\db\Query;
use think\db\ConnectionInterface;
use PeanutAdmin\Kernel\Support\TraceId;
use PeanutAdmin\Kernel\Sequence\AtomicSequenceStore;
use PeanutAdmin\Kernel\Sequence\SequenceGenerator;
use PeanutAdmin\Kernel\Sequence\RedisSequenceStore;
use PeanutAdmin\Kernel\Concurrency\ConcurrencyServices;
use Symfony\Component\Lock\Store\FlockStore;
use PeanutAdmin\Kernel\Support\DataMasker;
use PeanutAdmin\Kernel\Support\FieldDiffCalculator;
use PeanutAdmin\Kernel\Tree\TreeBuilder;
use PHPUnit\Framework\TestCase;

final class FoundationTest extends TestCase
{
    public function testTreeProtectsAgainstCyclesAndDeletion(): void
    {
        $tree = new TreeBuilder();
        $rows = [['id' => 1, 'parent_id' => null], ['id' => 2, 'parent_id' => 1]];
        self::assertSame(2, $tree->build($rows)[0]['children'][0]['id']);
        try { $tree->assertCanDelete($rows, 1); self::fail('Expected delete guard'); } catch (\DomainException) {}
        $this->expectException(\InvalidArgumentException::class);
        $tree->assertCanMove($rows, 1, 2);
    }

    public function testQueryPreservesExistingScopeAndUsesRegisteredFields(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $query = new Query($connection);
        $query->pk('id');
        $query->where('tenant_id', 42);
        $builder = new DeclarativeQueryBuilder(
            ['name' => 'name'],
            ['owner' => static function (Query $query, mixed $value): void { $query->where('owner_id', $value); }],
        );
        $builder->apply($query, [
            ['field' => 'name', 'operator' => 'like', 'value' => '%alice%'],
            ['field' => 'owner', 'operator' => 'hasWhere', 'value' => 7],
        ], new PageRequest(2, 10), ['name' => 'asc']);
        $options = $query->getOptions();
        self::assertArrayHasKey('where', $options);
        self::assertStringContainsString('tenant_id', var_export($options['where'], true));
        self::assertStringContainsString('owner_id', var_export($options['where'], true));
        self::assertSame('10,10', $options['limit']);
        self::assertFalse($builder->hasWhere([['field' => 'name', 'operator' => '=']], 'name'));
        self::assertTrue($builder->hasWhere([['field' => 'owner', 'operator' => 'hasWhere']], 'owner'));
    }

    public function testInvalidQueryValuesAndMissingTreeNodeAreRejected(): void
    {
        $connection = $this->createStub(ConnectionInterface::class);
        $query = new Query($connection);
        $query->pk('id');
        $builder = new DeclarativeQueryBuilder(['name' => 'name']);
        foreach ([
            ['field' => 'name', 'operator' => '=', 'value' => []],
            ['field' => 'name', 'operator' => 'like', 'value' => 12],
            ['field' => 'name', 'operator' => 'in', 'value' => [['nested']]],
            ['field' => 'name', 'operator' => 'between', 'value' => [1, []]],
        ] as $filter) {
            try {
                $builder->apply($query, [$filter]);
                self::fail('Invalid filter was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            (new PageRequest(PHP_INT_MAX, 100))->offset();
            self::fail('Overflowing page was accepted.');
        } catch (\PeanutAdmin\Kernel\Authorization\Application\AdminAccessException) {
            self::assertTrue(true);
        }
        $this->expectException(\InvalidArgumentException::class);
        (new TreeBuilder())->assertCanDelete([['id' => 1, 'parent_id' => 99]], 99);
    }

    public function testTraceSequenceAndNativeLock(): void
    {
        self::assertTrue(TraceId::acceptsHeaderValue('a'));
        self::assertFalse(TraceId::acceptsHeaderValue(' a'));
        self::assertMatchesRegularExpression('/^http-[a-f0-9]{32}$/D', TraceId::generate(' HTTP '));
        $store = new class implements AtomicSequenceStore {
            private int $value = 0;
            public function next(string $key): int { return ++$this->value; }
        };
        $sequence = new SequenceGenerator($store);
        self::assertSame(1, $sequence->next('a'));
        self::assertSame(2, $sequence->next('a'));
        $redis = new class {
            public array $keys = [];
            public function incr(string $key): int { $this->keys[] = $key; return count($this->keys); }
        };
        $redisSequence = new SequenceGenerator(new RedisSequenceStore($redis, 'tenant:42'));
        self::assertSame(1, $redisSequence->next('orders'));
        self::assertSame(['tenant:42:orders'], $redis->keys);
        $lock = ConcurrencyServices::locks(new FlockStore(sys_get_temp_dir()))->createLock('peanut-' . bin2hex(random_bytes(8)));
        self::assertTrue($lock->acquire());
        $lock->release();
    }

    public function testMaskDiffAndTransitions(): void
    {
        $masker = new DataMasker();
        self::assertSame('张**', $masker->mask('张三丰', 1));
        self::assertSame('***', $masker->phone('123'));
        self::assertSame('138****8000', $masker->phone('13800138000'));
        self::assertSame('a***@example.com', $masker->email('abcd@example.com'));
        self::assertSame('张*', $masker->name('张三'));
        self::assertSame('123', $masker->identity('123', true));
        self::assertSame(['a' => ['before' => 1, 'after' => 2]], (new FieldDiffCalculator())->calculate(['a' => 1], ['a' => 2]));
        $machine = new StateMachine(['draft' => ['ready']]);
        self::assertSame('ready', $machine->transition('draft', 'ready'));
        self::assertFalse($machine->canTransition('ready', 'draft'));
    }
}
