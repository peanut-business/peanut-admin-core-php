<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Webhook;

use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;

final readonly class WebhookDestinationPolicy
{
    public function __construct(private HostAddressResolver $resolver) {}

    public function approve(string $url): WebhookDestination
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[^\x21-\x7e]/', $url) === 1) {
            throw IntegrationSecurityException::destinationDenied();
        }
        $parts = parse_url($url);
        if (!is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !isset($parts['host']) || !is_string($parts['host'])
            || (isset($parts['port']) && $parts['port'] !== 443)
        ) {
            throw IntegrationSecurityException::destinationDenied();
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === '' || strlen($host) > 253 || str_contains($host, '%')) {
            throw IntegrationSecurityException::destinationDenied();
        }
        if ($this->isDeniedName($host)) {
            throw IntegrationSecurityException::destinationDenied();
        }
        $ipLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if (!$ipLiteral && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) !== 1) {
            throw IntegrationSecurityException::destinationDenied();
        }
        $addresses = $ipLiteral ? [$host] : $this->resolver->resolve($host);
        $approved = [];
        foreach ($addresses as $address) {
            $canonical = $this->publicAddress($address);
            $approved[$canonical] = true;
        }
        if ($approved === []) {
            throw IntegrationSecurityException::destinationDenied();
        }
        $approvedAddresses = array_keys($approved);
        sort($approvedAddresses, SORT_STRING);
        $canonicalUrl = 'https://' . (str_contains($host, ':') ? '[' . $host . ']' : $host);
        $canonicalUrl .= $parts['path'] ?? '/';
        if (isset($parts['query'])) {
            $canonicalUrl .= '?' . $parts['query'];
        }
        return new WebhookDestination($canonicalUrl, $host, 443, $approvedAddresses);
    }

    private function isDeniedName(string $host): bool
    {
        if (in_array($host, ['localhost', 'metadata', 'metadata.google.internal', 'instance-data'], true)) {
            return true;
        }
        foreach (['.localhost', '.local', '.internal', '.home', '.lan'] as $suffix) {
            if (str_ends_with($host, $suffix)) return true;
        }
        return false;
    }

    private function publicAddress(string $address): string
    {
        $canonical = filter_var($address, FILTER_VALIDATE_IP);
        if (!is_string($canonical)) {
            throw IntegrationSecurityException::destinationDenied();
        }
        $flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
        if (filter_var($canonical, FILTER_VALIDATE_IP, $flags) === false) {
            throw IntegrationSecurityException::destinationDenied();
        }
        return strtolower($canonical);
    }
}
