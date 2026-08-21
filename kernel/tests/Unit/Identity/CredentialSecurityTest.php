<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Identity;

use InvalidArgumentException;
use PeanutAdmin\Kernel\Identity\EmailAddress;
use PeanutAdmin\Kernel\Identity\PasswordHasher;
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
}
