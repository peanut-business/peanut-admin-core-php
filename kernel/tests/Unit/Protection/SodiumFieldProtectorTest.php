<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Tests\Unit\Protection;

use DateTimeImmutable;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PeanutAdmin\Kernel\Context\AuthorizationDecision;
use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;
use PeanutAdmin\Kernel\Context\RequestedTargetSet;
use PeanutAdmin\Kernel\Protection\FieldProtectionContext;
use PeanutAdmin\Kernel\Protection\FieldProtectionException;
use PeanutAdmin\Kernel\Protection\SodiumFieldProtector;
use PHPUnit\Framework\TestCase;

final class SodiumFieldProtectorTest extends TestCase
{
    private const PHONE = '+86-13800001111';

    public function testContactFieldRoundTripHasRandomEnvelopeAndRequiresExactAuthorizedTarget(): void
    {
        $protector = new SodiumFieldProtector(['v1' => random_bytes(32)], 'v1');
        $field = $this->field();
        $first = $protector->protect(self::PHONE, $field);
        $second = $protector->protect(self::PHONE, $field);

        self::assertSame(1, $first['version']);
        self::assertSame('v1', $first['key_id']);
        self::assertNotSame($first['nonce'], $second['nonce']);
        self::assertNotSame($first['ciphertext'], $second['ciphertext']);
        self::assertStringNotContainsString(self::PHONE, json_encode($first, JSON_THROW_ON_ERROR));

        // MySQL JSON storage can change object key order.
        $decoded = json_decode(json_encode(array_reverse($first, true), JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(self::PHONE, $protector->reveal($decoded, $field, $this->authorized(), 'sales.contact.reveal'));
    }

    public function testCrossTenantResourceRecordAndFieldCannotDecrypt(): void
    {
        $protector = new SodiumFieldProtector(['v1' => random_bytes(32)], 'v1');
        $first = $protector->protect(self::PHONE, $this->field());
        foreach ([
            new FieldProtectionContext(42, 'sales.contact', 'contact-123', 'mobile'),
            new FieldProtectionContext(41, 'sales.lead', 'contact-123', 'mobile'),
            new FieldProtectionContext(41, 'sales.contact', 'contact-456', 'mobile'),
            new FieldProtectionContext(41, 'sales.contact', 'contact-123', 'email'),
        ] as $differentField) {
            try {
                $protector->rotate($first, $differentField);
                self::fail('Cross-bound field must not decrypt.');
            } catch (FieldProtectionException $error) {
                self::assertSame('FIELD_PROTECTION_UNAVAILABLE', $error->errorCode);
            }
        }
    }

    public function testWrongOperationTargetResourceOrTenantDeniesReveal(): void
    {
        $protector = new SodiumFieldProtector(['v1' => random_bytes(32)], 'v1');
        $envelope = $protector->protect(self::PHONE, $this->field());
        foreach ([
            [$this->authorized(41, 'sales.contact', 'sales.contact.read', 'contact-123'), 'sales.contact.reveal'],
            [$this->authorized(41, 'sales.contact', 'sales.contact.reveal', 'contact-456'), 'sales.contact.reveal'],
            [$this->authorized(41, 'sales.lead', 'sales.contact.reveal', 'contact-123'), 'sales.contact.reveal'],
            [$this->authorized(42, 'sales.contact', 'sales.contact.reveal', 'contact-123'), 'sales.contact.reveal'],
            [$this->authorized(), 'sales.contact.update'],
        ] as [$wrongGrant, $expectedOperation]) {
            try {
                $protector->reveal($envelope, $this->field(), $wrongGrant, $expectedOperation);
                self::fail('Reveal needs exact granted tenant, operation and record.');
            } catch (FieldProtectionException $error) {
                self::assertSame('FIELD_REVEAL_DENIED', $error->errorCode);
            }
        }
    }

    public function testKeyRotationChangesKeyIdAndFailsWithoutOriginalKey(): void
    {
        $oldKey = random_bytes(32);
        $newKey = random_bytes(32);
        $field = $this->field();
        $old = new SodiumFieldProtector(['old' => $oldKey], 'old');
        $original = $old->protect(self::PHONE, $field);
        $mixed = new SodiumFieldProtector(['old' => $oldKey, 'new' => $newKey], 'new');
        $rotated = $mixed->rotate($original, $field);

        self::assertSame('new', $rotated['key_id']);
        self::assertSame(self::PHONE, $mixed->reveal($rotated, $field, $this->authorized(), 'sales.contact.reveal'));
        self::assertSame(self::PHONE, $mixed->reveal($original, $field, $this->authorized(), 'sales.contact.reveal'));
        $newOnly = new SodiumFieldProtector(['new' => $newKey], 'new');
        try {
            $newOnly->rotate($original, $field);
            self::fail('Missing historical key must fail without a replacement.');
        } catch (FieldProtectionException $error) {
            self::assertSame('FIELD_PROTECTION_UNAVAILABLE', $error->errorCode);
            self::assertSame('old', $original['key_id']);
            self::assertSame(self::PHONE, $old->reveal($original, $field, $this->authorized(), 'sales.contact.reveal'));
        }
    }

    public function testInvalidEnvelopeFailsClosedWithoutLeakingValue(): void
    {
        $protector = new SodiumFieldProtector(['v1' => random_bytes(32)], 'v1');
        $field = $this->field();
        $original = $protector->protect(self::PHONE, $field);
        foreach ([
            array_replace($original, ['version' => 2]),
            array_replace($original, ['key_id' => 'missing']),
            array_replace($original, ['ciphertext' => base64_encode(random_bytes(45))]),
            array_replace($original, ['nonce' => 'invalid']),
            array_replace($original, ['unexpected' => 'extra']),
        ] as $tampered) {
            try {
                $protector->reveal($tampered, $field, $this->authorized(), 'sales.contact.reveal');
                self::fail('Tampered data must fail.');
            } catch (FieldProtectionException $error) {
                self::assertSame('FIELD_PROTECTION_UNAVAILABLE', $error->errorCode);
                self::assertStringNotContainsString(self::PHONE, $error->getMessage());
            }
        }
    }

    public function testSensitiveArgumentsDoNotAppearInExceptionTraces(): void
    {
        $keyMarker = str_repeat('K', 32);
        try {
            new SodiumFieldProtector(['marker' => $keyMarker], 'missing');
            self::fail('Expected unavailable key error.');
        } catch (FieldProtectionException $error) {
            self::assertStringNotContainsString($keyMarker, print_r($error->getTrace(), true));
        }

        $plaintextMarker = 'DO-NOT-LOG-THIS-PLAINTEXT';
        $plaintext = $plaintextMarker . str_repeat('x', 4096);
        $protector = new SodiumFieldProtector(['marker' => str_repeat('v', 32)], 'marker');
        try {
            $protector->protect($plaintext, $this->field());
            self::fail('Expected invalid value error.');
        } catch (FieldProtectionException $error) {
            self::assertStringNotContainsString($plaintextMarker, print_r($error->getTrace(), true));
        }
    }

    public function testOversizedEncodedEnvelopeRejectedBeforeDecode(): void
    {
        $protector = new SodiumFieldProtector(['v1' => str_repeat('k', 32)], 'v1');
        $field = $this->field();
        $envelope = $protector->protect(self::PHONE, $field);
        foreach ([
            ['nonce' => $envelope['nonce'] . str_repeat(' ', 10000)],
            ['ciphertext' => $envelope['ciphertext'] . str_repeat(' ', 10000)],
        ] as $mutation) {
            try {
                $protector->rotate(array_replace($envelope, $mutation), $field);
                self::fail('Noncanonical oversized base64 input must fail.');
            } catch (FieldProtectionException $error) {
                self::assertSame('FIELD_PROTECTION_UNAVAILABLE', $error->errorCode);
            }
        }
        self::assertSame(self::PHONE, $protector->reveal($envelope, $field, $this->authorized(), 'sales.contact.reveal'));
    }

    public function testNumericKeyIdCanBeUsedAsVersionMarker(): void
    {
        $protector = new SodiumFieldProtector(['1' => str_repeat('a', 32)], '1');
        $field = $this->field();
        $envelope = $protector->protect(self::PHONE, $field);
        self::assertSame('1', $envelope['key_id']);
        self::assertSame(self::PHONE, $protector->reveal($envelope, $field, $this->authorized(), 'sales.contact.reveal'));
    }

    private function field(): FieldProtectionContext
    {
        return new FieldProtectionContext(41, 'sales.contact', 'contact-123', 'mobile');
    }

    private function authorized(
        int $tenantId = 41,
        string $resource = 'sales.contact',
        string $operation = 'sales.contact.reveal',
        string $recordId = 'contact-123',
    ): AuthorizedOperationContext {
        $tenant = TenantContext::fromValidatedSession(new ValidatedTenantSession(
            1, 'test-session', $tenantId, 4, 7, 'admin', new DateTimeImmutable('2026-11-11T00:00:00Z'), 1, 1,
        ), 'test-request');
        return AuthorizedOperationContext::fromDecision(AuthorizationDecision::allow(
            $tenant,
            $resource,
            $operation,
            [new RequestedTargetSet('sales.contact', [$recordId])],
            'evaluated-policy-digest',
        ));
    }
}
