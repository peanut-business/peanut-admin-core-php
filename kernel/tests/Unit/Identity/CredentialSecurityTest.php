<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Identity;

use InvalidArgumentException;
use RuntimeException;
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

    public function testPasswordRequirementsExposeEffectiveOptionalCharacterRules(): void
    {
        $default = new PasswordPolicy();
        self::assertSame([
            'require_uppercase' => false,
            'require_lowercase' => false,
            'require_digit' => false,
            'require_special' => false,
        ], $default->requirements());

        $restricted = new PasswordPolicy(8, 32, true, true, true, true);
        self::assertSame([
            'require_uppercase' => true,
            'require_lowercase' => true,
            'require_digit' => true,
            'require_special' => true,
        ], $restricted->requirements());
        $restricted->assertValid('Example9!');

        $this->expectException(RuntimeException::class);
        $restricted->assertValid('Example99');
    }

    public function testOverriddenPublicRequirementsAreEnforcedForNewCredentials(): void
    {
        $policy = new class (8, 32) extends PasswordPolicy {
            public function requirements(): array
            {
                return [...parent::requirements(), 'require_digit' => true];
            }
        };

        self::assertTrue($policy->requirements()['require_digit']);
        $policy->assertValid('example9');

        $this->expectException(RuntimeException::class);
        $policy->assertValid('examples');
    }

    public function testInvalidRequirementsFromSubclassFailClosed(): void
    {
        $policy = new class extends PasswordPolicy {
            public function requirements(): array
            {
                return ['require_uppercase' => true];
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $policy->assertValid('Example9!');
    }

    public function testDefaultLengthBoundAppliesOnlyToNewCredentials(): void
    {
        $policy = new PasswordPolicy();
        self::assertSame(32, $policy->maximumLength());
        $legacyPassword = str_repeat('a', 48);
        $hasher = new PasswordHasher();
        $oldHash = $hasher->hash($legacyPassword);
        self::assertTrue($hasher->verify($legacyPassword, $oldHash));

        $this->expectException(RuntimeException::class);
        $policy->assertValid($legacyPassword);
    }

    public function testDefaultPasswordPolicyIsLooseForReusableCore(): void
    {
        $policy = new PasswordPolicy();

        self::assertSame(PasswordPolicy::DEFAULT_MINIMUM_LENGTH, $policy->minimumLength());
        self::assertSame(PasswordPolicy::DEFAULT_MAXIMUM_LENGTH, $policy->maximumLength());
        $policy->assertValid('eight888');
    }
}
