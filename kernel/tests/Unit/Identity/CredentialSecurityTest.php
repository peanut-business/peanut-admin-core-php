<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Identity;

use InvalidArgumentException;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
use PeanutAdmin\Kernel\Identity\PasswordPolicy;
use PHPUnit\Framework\TestCase;

final class CredentialSecurityTest extends TestCase
{
    public function testEmailIsTrimmedAndNormalizedWithoutChangingTags(): void
    {
        self::assertSame(
            'owner+tag@example.com',
            EmailAddress::fromString('  Owner+Tag@Example.COM ')->value(),
        );
    }

    public function testInvalidEmailIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        EmailAddress::fromString('not-an-email');
    }

    public function testPasswordUsesArgon2idAndNeverReturnsPlaintext(): void
    {
        $hasher = new PasswordHasher();
        $hash = $hasher->hash('correct horse battery staple');

        self::assertStringStartsWith('$argon2id$', $hash);
        self::assertStringNotContainsString('correct horse battery staple', $hash);
        self::assertTrue($hasher->verify('correct horse battery staple', $hash));
        self::assertFalse($hasher->verify('wrong password', $hash));
    }

    public function testPasswordPolicyCanBeConfiguredByTheApplication(): void
    {
        $policy = new PasswordPolicy(12, 128);

        self::assertSame(12, $policy->minimumLength());
        self::assertSame(128, $policy->maximumLength());
        $policy->assertValid('application-policy-password');
        self::assertNotEmpty((new PasswordHasher())->hash('short'));

        $this->expectException(\RuntimeException::class);
        $policy->assertValid('short');
    }

    public function testDefaultPasswordPolicyIsLooseForReusableCore(): void
    {
        $policy = new PasswordPolicy();

        self::assertSame(PasswordPolicy::DEFAULT_MINIMUM_LENGTH, $policy->minimumLength());
        self::assertSame(PasswordPolicy::DEFAULT_MAXIMUM_LENGTH, $policy->maximumLength());
        $policy->assertValid('eight888');
    }
}
