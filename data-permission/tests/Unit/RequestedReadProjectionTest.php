<?php
declare(strict_types=1);

use PeanutAdmin\DataPermission\Scope\AuthorizedReadScope;
use PeanutAdmin\DataPermission\Scope\SourceReadScope;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PHPUnit\Framework\TestCase;

/** 显式投影属于本次授权结果，必须在重新核验时完整保留。 */
final class RequestedReadProjectionTest extends TestCase
{
    private function actor(): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(1, 'test-session', 101, 10, 11, 'admin-web', new DateTimeImmutable('2031-01-01T00:00:00Z'), 1), 'test-request');
    }

    public function testRequestedFieldsAreRetainedForReauthorization(): void
    {
        $scope = new AuthorizedReadScope($this->actor(), 'inventory.summary', 'read', [new SourceReadScope(202, [1], [], ['id', 'quantity'], 'source-r1')], 'r1', ['id', 'quantity']);
        self::assertSame(['id', 'quantity'], $scope->requestedFields);
        self::assertSame([202], $scope->sourceTenantIds());
        $scope->assertFields(['quantity']);
        self::assertSame(101, $scope->actor->tenantId);
    }

    public function testRequestedProjectionCannotExceedSourceGrant(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuthorizedReadScope($this->actor(), 'inventory.summary', 'read', [new SourceReadScope(202, [1], [], ['id'], 'source-r1')], 'r1', ['amount']);
    }

    public function testDuplicateFieldIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AuthorizedReadScope($this->actor(), 'inventory.summary', 'read', [new SourceReadScope(202, [1], [], ['id'], 'source-r1')], 'r1', ['id', 'id']);
    }

    public function testDefaultCommonProjectionRemainsCompatible(): void
    {
        $scope = new AuthorizedReadScope($this->actor(), 'inventory.summary', 'read', [new SourceReadScope(202, [1], [], ['id'], 'source-r1')], 'r1');
        self::assertSame([], $scope->requestedFields);
        $scope->assertFields(['id']);
    }
}
