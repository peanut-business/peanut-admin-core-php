<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Webhook;

use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;

final readonly class WebhookDestinationPolicy
{
    /** @var list<array{string,int}> */
    private const DENIED_V4 = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
        ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
        ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
        ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];

    /** @var list<array{string,int}> */
    private const DENIED_V6 = [
        ['::', 96], ['::ffff:0:0', 96], ['64:ff9b::', 96], ['64:ff9b:1::', 48],
        ['100::', 64], ['2001::', 23], ['2001:db8::', 32], ['2002::', 16],
        ['3fff::', 20], ['5f00::', 16], ['fc00::', 7], ['fe80::', 10], ['ff00::', 8],
    ];

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
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
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
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }
        return false;
    }

    private function publicAddress(string $address): string
    {
        $address = trim($address);
        if (str_starts_with($address, '[') && str_ends_with($address, ']')) {
            $address = substr($address, 1, -1);
        }
        $packed = @inet_pton($address);
        if (!is_string($packed)) {
            throw IntegrationSecurityException::destinationDenied();
        }
        $ranges = strlen($packed) === 4 ? self::DENIED_V4 : self::DENIED_V6;
        foreach ($ranges as [$network, $prefix]) {
            $networkPacked = inet_pton($network);
            if (is_string($networkPacked) && $this->matchesPrefix($packed, $networkPacked, $prefix)) {
                throw IntegrationSecurityException::destinationDenied();
            }
        }
        $canonical = inet_ntop($packed);
        if (!is_string($canonical)) {
            throw IntegrationSecurityException::destinationDenied();
        }
        return strtolower($canonical);
    }

    private function matchesPrefix(string $address, string $network, int $prefix): bool
    {
        if (strlen($address) !== strlen($network)) {
            return false;
        }
        $wholeBytes = intdiv($prefix, 8);
        if ($wholeBytes > 0 && substr($address, 0, $wholeBytes) !== substr($network, 0, $wholeBytes)) {
            return false;
        }
        $remaining = $prefix % 8;
        if ($remaining === 0) {
            return true;
        }
        $mask = (0xff << (8 - $remaining)) & 0xff;
        return (ord($address[$wholeBytes]) & $mask) === (ord($network[$wholeBytes]) & $mask);
    }
}
