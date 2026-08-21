<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Application;

final readonly class SessionDevice
{
    public function __construct(
        public string $sessionKey,
        public string $clientKey,
        public string $status,
        public bool $current,
        public ?string $maskedIp,
        public ?string $userAgentFingerprint,
        public string $issuedAt,
        public string $lastSeenAt,
        public string $absoluteExpiresAt,
        public ?string $revokedAt,
    ) {}
}
