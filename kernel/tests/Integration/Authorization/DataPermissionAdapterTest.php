<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Integration\Authorization;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Authorization\DataPermissionAdapter;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PHPUnit\Framework\TestCase;
use stdClass;

final class DataPermissionAdapterTest extends TestCase
{
    public function testKernelPortDelegatesWithoutDependingOnTheEnginePackage(): void
    {
        $queryCalls = 0;
        $targetCalls = 0;
        $constraint = new stdClass();
        $adapter = new DataPermissionAdapter(
            static function () use (&$queryCalls, $constraint): object {
                ++$queryCalls;

                return $constraint;
            },
            static function () use (&$targetCalls): void {
                ++$targetCalls;
            },
        );
        $context = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1,
            'session',
            10,
            20,
            30,
            'web',
            new DateTimeImmutable('2026-07-16T10:00:00Z'),
            1,
        ), 'request');
        $targets = [new RequestedTargetSet('example.project', ['A'])];

        self::assertSame($constraint, $adapter->queryConstraint(
            $context,
            'example.work-item',
            'list',
            $targets,
        ));
        $adapter->assertTargetsAllowed($context, 'example.work-item', 'update', $targets);
        self::assertSame(1, $queryCalls);
        self::assertSame(1, $targetCalls);
    }
}
