<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Webhook;

interface HostAddressResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
