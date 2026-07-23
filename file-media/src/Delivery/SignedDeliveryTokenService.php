<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Delivery;

use DateTimeImmutable;
use JsonException;
use PeanutAdmin\FileMedia\Application\FileMediaException;

final readonly class SignedDeliveryTokenService
{
    public function __construct(
        private string $secret,
        private ReplayGuard $replayGuard,
        private int $maxTtlSeconds = 3600,
    ) {
        if (strlen($secret) < 32 || $maxTtlSeconds < 1 || $maxTtlSeconds > 86400) {
            throw FileMediaException::deliveryInvalid();
        }
    }

    public function issue(
        int $tenantId,
        string $fileKey,
        DeliveryVisibility $visibility,
        ReplayMode $replayMode,
        DateTimeImmutable $issuedAt,
        int $ttlSeconds,
        ?string $tokenId = null,
    ): string {
        $tokenId ??= bin2hex(random_bytes(16));
        if ($tenantId < 1 || preg_match('/^file_[0-9a-f]{32}$/D', $fileKey) !== 1
            || preg_match('/^[0-9a-f]{32}$/D', $tokenId) !== 1
            || $ttlSeconds < 1 || $ttlSeconds > $this->maxTtlSeconds
            || ($visibility === DeliveryVisibility::Private
                && ($replayMode !== ReplayMode::SingleUse || $ttlSeconds > 300))
        ) {
            throw FileMediaException::deliveryInvalid();
        }
        $payload = $this->encode(json_encode([
            'v' => 1,
            'tid' => $tenantId,
            'fk' => $fileKey,
            'vis' => $visibility->value,
            'replay' => $replayMode->value,
            'iat' => $issuedAt->getTimestamp(),
            'exp' => $issuedAt->getTimestamp() + $ttlSeconds,
            'jti' => $tokenId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $payload . '.' . $this->encode(hash_hmac('sha256', $payload, $this->secret, true));
    }

    public static function peekTenantId(string $token, string $secret): int
    {
        if (strlen($secret) < 32) {
            throw FileMediaException::deliveryDenied();
        }
        $claims = self::verifiedClaims($token, $secret);
        if ($claims['v'] !== 1 || !is_int($claims['tid']) || $claims['tid'] < 1) {
            throw FileMediaException::deliveryDenied();
        }

        return $claims['tid'];
    }

    /** @return array{visibility: DeliveryVisibility, replay_mode: ReplayMode, expires_at: DateTimeImmutable, token_id: string} */
    public function verifyAndConsume(
        string $token,
        int $tenantId,
        string $fileKey,
        DateTimeImmutable $now,
    ): array {
        $claims = self::verifiedClaims($token, $this->secret);
        if ($claims['v'] !== 1 || $claims['tid'] !== $tenantId || $claims['fk'] !== $fileKey
            || !is_int($claims['iat']) || !is_int($claims['exp']) || !is_string($claims['jti'])
            || preg_match('/^[0-9a-f]{32}$/D', $claims['jti']) !== 1
            || $claims['iat'] > $now->getTimestamp() + 30 || $claims['exp'] <= $now->getTimestamp()
            || $claims['exp'] <= $claims['iat'] || $claims['exp'] - $claims['iat'] > $this->maxTtlSeconds
        ) {
            throw FileMediaException::deliveryDenied();
        }
        $visibility = DeliveryVisibility::tryFrom(is_string($claims['vis']) ? $claims['vis'] : '');
        $replay = ReplayMode::tryFrom(is_string($claims['replay']) ? $claims['replay'] : '');
        if (!$visibility instanceof DeliveryVisibility || !$replay instanceof ReplayMode) {
            throw FileMediaException::deliveryDenied();
        }
        if ($visibility === DeliveryVisibility::Private
            && ($replay !== ReplayMode::SingleUse || $claims['exp'] - $claims['iat'] > 300)
        ) {
            throw FileMediaException::deliveryDenied();
        }
        $expiresAt = (new DateTimeImmutable('@' . $claims['exp']))->setTimezone($now->getTimezone());
        if ($replay === ReplayMode::SingleUse && !$this->replayGuard->consume($claims['jti'], $expiresAt, $now)) {
            throw FileMediaException::deliveryDenied();
        }

        return ['visibility' => $visibility, 'replay_mode' => $replay, 'expires_at' => $expiresAt, 'token_id' => $claims['jti']];
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw FileMediaException::deliveryDenied();
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw FileMediaException::deliveryDenied();
        }

        return $decoded;
    }

    /** @return array{v: mixed, tid: mixed, fk: mixed, vis: mixed, replay: mixed, iat: mixed, exp: mixed, jti: mixed} */
    private static function verifiedClaims(string $token, string $secret): array
    {
        if (strlen($token) > 2048) {
            throw FileMediaException::deliveryDenied();
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2 || strlen($parts[0]) > 1536
            || preg_match('/^[A-Za-z0-9_-]+$/D', $parts[0]) !== 1
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $parts[1]) !== 1
            || !hash_equals(self::encodeValue(hash_hmac('sha256', $parts[0], $secret, true)), $parts[1])
        ) {
            throw FileMediaException::deliveryDenied();
        }
        try {
            $claims = json_decode(self::decodeValue($parts[0]), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw FileMediaException::deliveryDenied();
        }
        if (!is_array($claims) || array_keys($claims) !== ['v', 'tid', 'fk', 'vis', 'replay', 'iat', 'exp', 'jti']) {
            throw FileMediaException::deliveryDenied();
        }

        return $claims;
    }

    private static function encodeValue(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decodeValue(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $value) !== 1) {
            throw FileMediaException::deliveryDenied();
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if (!is_string($decoded)) {
            throw FileMediaException::deliveryDenied();
        }

        return $decoded;
    }
}
