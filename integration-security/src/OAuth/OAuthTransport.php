<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\OAuth;

/** Transport boundary for provider-specific OAuth protocols. */
interface OAuthTransport
{
    /**
     * Creates a browser authorization URL for a provider-specific scene.
     *
     * @param array<string, mixed> $config
     */
    public function authorizationUrl(string $scene, array $config, string $redirectUri, string $state): string;

    /**
     * Exchanges a single-use authorization code for a normalized identity.
     *
     * @param array<string, mixed> $config
     */
    public function exchange(string $scene, array $config, string $code): OAuthProfile;
}
