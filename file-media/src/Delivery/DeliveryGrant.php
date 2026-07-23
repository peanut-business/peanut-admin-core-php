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
        $parts = parse_url($uri);
        if ($adapterKey === '' || preg_match('/^[a-z][a-z0-9.-]{0,63}$/D', $adapterKey) !== 1 || strlen($uri) > 2048
            || !is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || preg_match('/^[0-9a-f]{32}$/D', $tokenId) !== 1
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
}
