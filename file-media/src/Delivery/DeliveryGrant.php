<?php

declare(strict_types=1);

namespace PeanutAdmin\FileMedia\Delivery;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\FileMedia\Application\FileMediaException;

final readonly class DeliveryGrant
{
    public function __construct(
        public string $adapterKey,
        public string $uri,
        public DateTimeImmutable $expiresAt,
        public DeliveryVisibility $visibility,
        public ReplayMode $replayMode,
        public string $tokenId,
    ) {
        if ($adapterKey === '' || preg_match('/^[a-z][a-z0-9.-]{0,63}$/D', $adapterKey) !== 1
            || preg_match('/^[0-9a-f]{32}$/D', $tokenId) !== 1
            || !self::isCanonicalHttpsUri($uri)
        ) {
            throw FileMediaException::deliveryUnavailable();
        }
    }

    /** @return array{adapter_key: string, visibility: string, replay_mode: string, expires_at: string} */
    public function auditMetadata(): array
    {
        return [
            'adapter_key' => $this->adapterKey,
            'visibility' => $this->visibility->value,
            'replay_mode' => $this->replayMode->value,
            'expires_at' => $this->expiresAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
        ];
    }

    private static function isCanonicalHttpsUri(string $uri): bool
    {
        if ($uri === '' || strlen($uri) > 2048 || !str_starts_with($uri, 'https://')
            || preg_match('/[^\x21-\x7e]/', $uri) === 1 || str_contains($uri, '\\')
            || preg_match('/%(?![0-9A-F]{2})/', $uri) === 1
        ) {
            return false;
        }
        $parts = parse_url($uri);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'], $parts['path']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['fragment'])
        ) {
            return false;
        }
        $host = $parts['host'];
        if ($host !== strtolower($host)
            || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) !== 1
        ) {
            return false;
        }
        $path = $parts['path'];
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '%')) {
            return false;
        }
        $decodedPath = rawurldecode($path);
        if (str_contains($decodedPath, '\\') || preg_match('/[\x00-\x1f\x7f]/', $decodedPath) === 1) {
            return false;
        }
        foreach (explode('/', substr($decodedPath, 1)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return !array_key_exists('query', $parts) || $parts['query'] !== '';
    }
}
