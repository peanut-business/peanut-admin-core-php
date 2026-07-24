<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Webhook;

final class SystemHostAddressResolver implements HostAddressResolver
{
    public function resolve(string $host): array
    {
        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($records)) {
            return [];
        }
        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[$address] = true;
            }
        }
        return array_keys($addresses);
    }
}
