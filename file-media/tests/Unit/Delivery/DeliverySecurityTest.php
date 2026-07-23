<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Tests\Unit\Delivery;

use DateTimeImmutable;
use PeanutAdmin\FileMedia\Application\FileMediaException;
use PeanutAdmin\FileMedia\Application\FileObject;
use PeanutAdmin\FileMedia\Delivery\DeliveryAdapter;
use PeanutAdmin\FileMedia\Delivery\DeliveryGrant;
use PeanutAdmin\FileMedia\Delivery\DeliveryPolicy;
use PeanutAdmin\FileMedia\Delivery\DeliveryRequest;
use PeanutAdmin\FileMedia\Delivery\DeliveryService;
use PeanutAdmin\FileMedia\Delivery\DeliveryVisibility;
use PeanutAdmin\FileMedia\Delivery\InMemoryReplayGuard;
use PeanutAdmin\FileMedia\Delivery\ReplayMode;
use PeanutAdmin\FileMedia\Delivery\SignedDeliveryTokenService;
use PeanutAdmin\Kernel\Auth\TenantContext;
use PeanutAdmin\Kernel\Auth\ValidatedTenantSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeliverySecurityTest extends TestCase
{
    public function testSignedTokenIsTenantBoundExpiringAndSingleUse(): void
    {
        $now = new DateTimeImmutable('2026-07-24T00:00:00Z');
        $service = new SignedDeliveryTokenService(str_repeat('s', 32), new InMemoryReplayGuard(), 300);
        $fileKey = 'file_' . str_repeat('a', 32);
        $token = $service->issue(7, $fileKey, DeliveryVisibility::Private, ReplayMode::SingleUse, $now, 60, str_repeat('b', 32));

        self::assertSame(7, SignedDeliveryTokenService::peekTenantId($token, str_repeat('s', 32)));
        $claims = $service->verifyAndConsume($token, 7, $fileKey, $now->modify('+1 second'));
        self::assertSame(DeliveryVisibility::Private, $claims['visibility']);
        $this->expectError('FILE_DELIVERY_DENIED', fn() => $service->verifyAndConsume($token, 7, $fileKey, $now->modify('+2 seconds')));
        $this->expectError('FILE_DELIVERY_DENIED', fn() => $service->verifyAndConsume($token, 8, $fileKey, $now->modify('+2 seconds')));
        $this->expectError('FILE_DELIVERY_DENIED', fn() => $service->verifyAndConsume($token . 'x', 7, $fileKey, $now));
        $this->expectError('FILE_DELIVERY_DENIED', fn() => SignedDeliveryTokenService::peekTenantId($token, str_repeat('x', 32)));
        $this->expectError('FILE_DELIVERY_DENIED', fn() => $service->verifyAndConsume(str_repeat('x', 2049), 7, $fileKey, $now));
        $expired = $service->issue(7, $fileKey, DeliveryVisibility::Private, ReplayMode::SingleUse, $now, 1, str_repeat('c', 32));
        $this->expectError('FILE_DELIVERY_DENIED', fn() => $service->verifyAndConsume($expired, 7, $fileKey, $now->modify('+1 second')));
        $this->expectError('FILE_DELIVERY_INVALID', fn() => $service->issue(
            7,
            $fileKey,
            DeliveryVisibility::Private,
            ReplayMode::Bounded,
            $now,
            60,
        ));
        $this->expectError('FILE_DELIVERY_INVALID', fn() => $service->issue(
            7,
            $fileKey,
            DeliveryVisibility::Private,
            ReplayMode::SingleUse,
            $now,
            301,
        ));
    }

    public function testPolicyRejectsPrivateReplayAndProviderContractDrift(): void
    {
        $this->expectError('FILE_DELIVERY_UNAVAILABLE', fn() => new DeliveryGrant(
            'cdn',
            'http://cdn.example.test/object',
            new DateTimeImmutable('+1 minute'),
            DeliveryVisibility::Public,
            ReplayMode::Bounded,
            str_repeat('a', 32),
        ));
        self::assertSame(['adapter_key', 'visibility', 'replay_mode', 'expires_at'], array_keys((new DeliveryGrant(
            'cdn',
            'https://cdn.example.test/object?signature=redacted',
            new DateTimeImmutable('2026-07-24T00:01:00Z'),
            DeliveryVisibility::Public,
            ReplayMode::Bounded,
            str_repeat('a', 32),
        ))->auditMetadata()));
        self::assertSame('https://cdn.example.test/object?credential=scope%2Fdate', (new DeliveryGrant(
            'cdn',
            'https://cdn.example.test/object?credential=scope%2Fdate',
            new DateTimeImmutable('2026-07-24T00:01:00Z'),
            DeliveryVisibility::Public,
            ReplayMode::Bounded,
            str_repeat('a', 32),
        ))->uri);
        self::assertSame(300, (new DeliveryPolicy())->privateMaxTtlSeconds);
    }

    /** @return iterable<string, array{string}> */
    public static function ambiguousDeliveryUris(): iterable
    {
        yield 'backslash authority confusion' => ['https://good.example\\evil/x'];
        yield 'userinfo' => ['https://user@good.example/object'];
        yield 'control' => ["https://good.example/object\nnext"];
        yield 'uppercase host' => ['https://GOOD.example/object'];
        yield 'trailing host dot' => ['https://good.example./object'];
        yield 'explicit default port' => ['https://good.example:443/object'];
        yield 'encoded traversal' => ['https://good.example/a/%2E%2E/b'];
        yield 'encoded separator' => ['https://good.example/a%2Fb'];
        yield 'duplicate separator' => ['https://good.example/a//b'];
        yield 'fragment' => ['https://good.example/object#fragment'];
        yield 'empty path' => ['https://good.example'];
        yield 'empty query' => ['https://good.example/object?'];
    }

    #[DataProvider('ambiguousDeliveryUris')]
    public function testDeliveryGrantRejectsAmbiguousUrls(string $uri): void
    {
        $this->expectError('FILE_DELIVERY_UNAVAILABLE', fn() => new DeliveryGrant(
            'cdn',
            $uri,
            new DateTimeImmutable('2026-07-24T00:01:00Z'),
            DeliveryVisibility::Public,
            ReplayMode::Bounded,
            str_repeat('a', 32),
        ));
    }

    public function testDeliveryIsTenantPermissionAndProviderBound(): void
    {
        $now = new DateTimeImmutable('2026-07-24T00:00:00Z');
        $file = new FileObject(
            1,
            'file_' . str_repeat('a', 32),
            7,
            'objects',
            'private-key',
            'photo.png',
            'image/png',
            4,
            str_repeat('b', 64),
            'ready',
            70,
            1,
            '2026-07-24T00:00:00.000Z',
            '2026-07-24T00:00:00.000Z',
            null,
        );
        $adapter = new class implements DeliveryAdapter {
            public function key(): string
            {
                return 'cdn';
            }
            public function supportsStorageProvider(string $providerKey): bool
            {
                return $providerKey === 'objects';
            }
            public function issue(DeliveryRequest $request): DeliveryGrant
            {
                return new DeliveryGrant(
                    $this->key(),
                    'https://cdn.example.test/object?signature=opaque',
                    $request->issuedAt->modify('+' . $request->ttlSeconds . ' seconds'),
                    $request->visibility,
                    $request->replayMode,
                    str_repeat('c', 32),
                );
            }
        };
        $service = new DeliveryService($adapter, new DeliveryPolicy());
        $request = new DeliveryRequest(
            $this->context(7),
            $file,
            DeliveryVisibility::Private,
            ReplayMode::SingleUse,
            $now,
            60,
            true,
        );
        self::assertSame('https://cdn.example.test/object?signature=opaque', $service->issue($request)->uri);

        $this->expectError('FILE_NOT_FOUND', fn() => new DeliveryRequest(
            $this->context(8),
            $file,
            DeliveryVisibility::Private,
            ReplayMode::SingleUse,
            $now,
            60,
            true,
        ));
        $this->expectError('FILE_DELIVERY_DENIED', fn() => new DeliveryRequest(
            $this->context(7),
            $file,
            DeliveryVisibility::Private,
            ReplayMode::SingleUse,
            $now,
            60,
            false,
        ));
        $this->expectError('FILE_DELIVERY_DENIED', fn() => $service->issue(new DeliveryRequest(
            $this->context(7),
            $file,
            DeliveryVisibility::Private,
            ReplayMode::Bounded,
            $now,
            60,
            true,
        )));
    }

    private function context(int $tenantId): TenantContext
    {
        return TenantContext::fromValidatedSession(new ValidatedTenantSession(
            70,
            '01J00000000000000000000000',
            $tenantId,
            70,
            700,
            'admin-web',
            new DateTimeImmutable('2030-01-01T00:00:00Z'),
            1,
        ), 'req_delivery');
    }

    private function expectError(string $code, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected {$code}");
        } catch (FileMediaException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
    }
}
