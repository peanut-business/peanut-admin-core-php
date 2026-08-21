<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Application;

final readonly class BrowserOAuthCallbackRoutes
{
    /**
     * @param array<string, string> $callbackPaths
     * @param array<string, string> $clientPaths
     * @param array<string, array<string, scalar>> $clientDefaults
     */
    public function __construct(
        private array $callbackPaths,
        private array $clientPaths,
        private array $clientDefaults = [],
    ) {}

    public function callbackUrl(string $origin, string $scene): string
    {
        $path = $this->callbackPaths[$scene] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('The browser OAuth callback scene is invalid.');
        }

        return rtrim($origin, '/') . $path;
    }

    /** @param array<string, mixed> $query */
    public function clientRedirectUrl(string $client, array $query): string
    {
        $path = $this->clientPaths[$client] ?? null;
        if (!is_string($path) || !str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('The browser OAuth callback client is invalid.');
        }

        $safeQuery = $this->clientDefaults[$client] ?? [];
        foreach (['code', 'state', 'error', 'error_description'] as $field) {
            $raw = $query[$field] ?? '';
            if (is_scalar($raw) && ($value = trim((string) $raw)) !== '') {
                $safeQuery[$field] = $value;
            }
        }

        return $path . ($safeQuery === [] ? '' : '?' . http_build_query($safeQuery, '', '&', PHP_QUERY_RFC3986));
    }
}
