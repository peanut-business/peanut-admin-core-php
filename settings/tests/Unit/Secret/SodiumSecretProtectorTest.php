<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Tests\Unit\Secret;

use PeanutAdmin\Settings\Application\SettingException;
use PeanutAdmin\Settings\Secret\SecretStorageContext;
use PeanutAdmin\Settings\Secret\SodiumSecretProtector;
use PHPUnit\Framework\TestCase;

final class SodiumSecretProtectorTest extends TestCase
{
    public function testEncryptsWithActiveKeyAndDecryptsWithoutDeterministicCiphertext(): void
    {
        $protector = new SodiumSecretProtector(
            ['current' => random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)],
            'current',
        );
        $context = SecretStorageContext::deployment('example.module:runtime-secret');

        $first = $protector->protect('runtime-only secret', $context);
        $second = $protector->protect('runtime-only secret', $context);

        self::assertSame('current', $first['key_id']);
        self::assertSame(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES, strlen($first['nonce']));
        self::assertNotSame($first['ciphertext'], $second['ciphertext']);
        self::assertSame('runtime-only secret', $protector->reveal(
            $first['ciphertext'],
            $first['nonce'],
            $first['key_id'],
            $context,
        ));
    }

    public function testBindsCiphertextToEveryStorageIdentityComponent(): void
    {
        $protector = new SodiumSecretProtector(
            ['current' => random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)],
            'current',
        );
        $origin = SecretStorageContext::target(
            'example.module:runtime-secret',
            41,
            'example.project',
            'project-1',
        );
        $encrypted = $protector->protect('bound-secret', $origin);

        foreach ([
            SecretStorageContext::target('example.module:other-secret', 41, 'example.project', 'project-1'),
            SecretStorageContext::tenant('example.module:runtime-secret', 41),
            SecretStorageContext::target('example.module:runtime-secret', 42, 'example.project', 'project-1'),
            SecretStorageContext::target('example.module:runtime-secret', 41, 'example.other', 'project-1'),
            SecretStorageContext::target('example.module:runtime-secret', 41, 'example.project', 'project-2'),
        ] as $differentContext) {
            try {
                $protector->reveal(
                    $encrypted['ciphertext'],
                    $encrypted['nonce'],
                    $encrypted['key_id'],
                    $differentContext,
                );
                self::fail('Expected storage context authentication failure.');
            } catch (SettingException $exception) {
                self::assertSame('SETTING_SECRET_UNAVAILABLE', $exception->errorCode);
                self::assertStringNotContainsString('bound-secret', $exception->getMessage());
            }
        }
    }

    public function testSupportsDecryptingPreviousKeyDuringRotation(): void
    {
        $old = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $new = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $context = SecretStorageContext::tenant('example.module:runtime-secret', 41);
        $oldProtector = new SodiumSecretProtector(['old' => $old], 'old');
        $encrypted = $oldProtector->protect('rotate me', $context);
        $rotated = new SodiumSecretProtector(['old' => $old, 'new' => $new], 'new');

        self::assertSame('rotate me', $rotated->reveal(
            $encrypted['ciphertext'],
            $encrypted['nonce'],
            $encrypted['key_id'],
            $context,
        ));
        self::assertSame('new', $rotated->protect('new value', $context)['key_id']);
    }

    public function testFailsClosedForTamperingUnknownKeysAndInvalidNonce(): void
    {
        $protector = new SodiumSecretProtector(
            ['current' => random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)],
            'current',
        );
        $context = SecretStorageContext::deployment('example.module:runtime-secret');
        $encrypted = $protector->protect('sensitive-value', $context);

        foreach ([
            [$encrypted['ciphertext'] ^ str_repeat("\x01", strlen($encrypted['ciphertext'])), $encrypted['nonce'], 'current'],
            [$encrypted['ciphertext'], $encrypted['nonce'], 'missing'],
            [$encrypted['ciphertext'], 'short', 'current'],
        ] as [$ciphertext, $nonce, $keyId]) {
            try {
                $protector->reveal($ciphertext, $nonce, $keyId, $context);
                self::fail('Expected secret authentication failure.');
            } catch (SettingException $exception) {
                self::assertSame('SETTING_SECRET_UNAVAILABLE', $exception->errorCode);
                self::assertStringNotContainsString('sensitive-value', $exception->getMessage());
                self::assertStringNotContainsString($keyId, $exception->getMessage());
            }
        }
    }

    public function testLoadsStrictBase64KeyMapFromEnvironmentValues(): void
    {
        $key = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $protector = SodiumSecretProtector::fromJson(
            json_encode(['runtime-key' => base64_encode($key)], JSON_THROW_ON_ERROR),
            'runtime-key',
        );
        $context = SecretStorageContext::target(
            'example.module:runtime-secret',
            41,
            'example.project',
            'project-1',
        );

        $encrypted = $protector->protect('environment secret', $context);
        self::assertSame('environment secret', $protector->reveal(
            $encrypted['ciphertext'],
            $encrypted['nonce'],
            $encrypted['key_id'],
            $context,
        ));
    }

    public function testRejectsMissingDuplicateMalformedShortAndUnknownEnvironmentKeys(): void
    {
        $valid = base64_encode(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES));
        foreach ([
            ['', 'active'],
            ['{"duplicate":"' . $valid . '","duplicate":"' . $valid . '"}', 'duplicate'],
            ['{"active":"%%%"}', 'active'],
            [json_encode(['active' => base64_encode('short')], JSON_THROW_ON_ERROR), 'active'],
            [json_encode(['known' => $valid], JSON_THROW_ON_ERROR), 'unknown'],
        ] as [$json, $active]) {
            try {
                SodiumSecretProtector::fromJson($json, $active);
                self::fail('Expected invalid key configuration to fail.');
            } catch (SettingException $exception) {
                self::assertSame('SETTING_SECRET_UNAVAILABLE', $exception->errorCode);
                self::assertStringNotContainsString($valid, $exception->getMessage());
            }
        }
    }

    public function testRejectsEmptyAndOversizedPlaintext(): void
    {
        $protector = new SodiumSecretProtector(
            ['current' => random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)],
            'current',
        );
        $context = SecretStorageContext::deployment('example.module:runtime-secret');

        foreach (['', str_repeat('x', 4097)] as $value) {
            try {
                $protector->protect($value, $context);
                self::fail('Expected invalid plaintext to fail.');
            } catch (SettingException $exception) {
                self::assertSame('SETTING_VALUE_INVALID', $exception->errorCode);
            }
        }
    }
}
