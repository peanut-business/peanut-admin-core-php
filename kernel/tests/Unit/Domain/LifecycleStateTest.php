<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Domain;

use DomainException;
use PeanutAdmin\Kernel\Identity\AccountStatus;
use PeanutAdmin\Kernel\Identity\CredentialStatus;
use PeanutAdmin\Kernel\Membership\TenantMemberStatus;
use PeanutAdmin\Kernel\Platform\PlatformOperatorStatus;
use PeanutAdmin\Kernel\Tenancy\TenantStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnitEnum;

final class LifecycleStateTest extends TestCase
{
    /** @return iterable<string, array{object, object}> */
    public static function validTransitions(): iterable
    {
        yield 'account lock' => [AccountStatus::Active, AccountStatus::Locked];
        yield 'account unlock' => [AccountStatus::Locked, AccountStatus::Active];
        yield 'account disable' => [AccountStatus::Active, AccountStatus::Disabled];
        yield 'account close' => [AccountStatus::Disabled, AccountStatus::Closed];
        yield 'credential lock' => [CredentialStatus::Active, CredentialStatus::Locked];
        yield 'credential revoke' => [CredentialStatus::Locked, CredentialStatus::Revoked];
        yield 'tenant provision' => [TenantStatus::Provisioning, TenantStatus::Active];
        yield 'tenant suspend' => [TenantStatus::Active, TenantStatus::Suspended];
        yield 'tenant resume' => [TenantStatus::Suspended, TenantStatus::Active];
        yield 'member activate' => [TenantMemberStatus::Pending, TenantMemberStatus::Active];
        yield 'member suspend' => [TenantMemberStatus::Active, TenantMemberStatus::Suspended];
        yield 'member rejoin' => [TenantMemberStatus::Left, TenantMemberStatus::Pending];
        yield 'operator suspend' => [PlatformOperatorStatus::Active, PlatformOperatorStatus::Suspended];
        yield 'operator resume' => [PlatformOperatorStatus::Suspended, PlatformOperatorStatus::Active];
    }

    #[DataProvider('validTransitions')]
    public function testValidTransitionReturnsDestination(UnitEnum $from, UnitEnum $to): void
    {
        self::assertSame($to, $this->transition($from, $to));
    }

    /** @return iterable<string, array{object, object}> */
    public static function invalidTransitions(): iterable
    {
        yield 'closed account cannot reopen' => [AccountStatus::Closed, AccountStatus::Active];
        yield 'revoked credential cannot reactivate' => [CredentialStatus::Revoked, CredentialStatus::Active];
        yield 'closed tenant cannot reopen' => [TenantStatus::Closed, TenantStatus::Active];
        yield 'pending member cannot suspend' => [TenantMemberStatus::Pending, TenantMemberStatus::Suspended];
        yield 'left member cannot activate directly' => [TenantMemberStatus::Left, TenantMemberStatus::Active];
        yield 'closed operator cannot reopen' => [PlatformOperatorStatus::Closed, PlatformOperatorStatus::Active];
    }

    #[DataProvider('invalidTransitions')]
    public function testInvalidTransitionIsRejected(UnitEnum $from, UnitEnum $to): void
    {
        $this->expectException(DomainException::class);
        $this->transition($from, $to);
    }

    private function transition(UnitEnum $from, UnitEnum $to): UnitEnum
    {
        return match (true) {
            $from instanceof AccountStatus && $to instanceof AccountStatus => $from->transitionTo($to),
            $from instanceof CredentialStatus && $to instanceof CredentialStatus => $from->transitionTo($to),
            $from instanceof TenantStatus && $to instanceof TenantStatus => $from->transitionTo($to),
            $from instanceof TenantMemberStatus && $to instanceof TenantMemberStatus => $from->transitionTo($to),
            $from instanceof PlatformOperatorStatus && $to instanceof PlatformOperatorStatus => $from->transitionTo($to),
            default => throw new DomainException('Lifecycle status types do not match.'),
        };
    }
}
