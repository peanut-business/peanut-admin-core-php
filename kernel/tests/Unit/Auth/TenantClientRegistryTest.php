<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Auth;

use InvalidArgumentException;
use PeanutAdmin\Kernel\Auth\TenantClientRegistry;
use PHPUnit\Framework\TestCase;

final class TenantClientRegistryTest extends TestCase
{
    public function testRegistryReturnsOnlyDeclaredClientsWithIndependentCookies(): void
    {
        $registry = new TenantClientRegistry(['single-store-web', 'multi-store-web']);

        self::assertSame('single-store-web', $registry->require('single-store-web')->key);
        self::assertSame(
            '__Host-pa_tenant_refresh_single-store-web',
            $registry->require('single-store-web')->refreshCookieName,
        );
        self::assertNotSame(
            $registry->require('single-store-web')->refreshCookieName,
            $registry->require('multi-store-web')->refreshCookieName,
        );
    }

    public function testUnknownDuplicateAndInvalidClientsFailClosed(): void
    {
        foreach (
            [
                static fn() => new TenantClientRegistry([]),
                static fn() => new TenantClientRegistry(['admin-web', 'admin-web']),
                static fn() => new TenantClientRegistry(['Admin Web']),
                static fn() => (new TenantClientRegistry(['admin-web']))->require('other-web'),
            ] as $operation
        ) {
            try {
                $operation();
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
                continue;
            }

            self::fail('Expected invalid Tenant Client configuration to fail.');
        }
    }
}
