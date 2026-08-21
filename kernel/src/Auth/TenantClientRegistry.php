<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use InvalidArgumentException;

final readonly class TenantClientRegistry
{
    /** @var array<string, TenantClient> */
    private array $clients;

    /** @param list<string> $clientKeys */
    public function __construct(array $clientKeys)
    {
        if ($clientKeys === []) {
            throw new InvalidArgumentException('At least one Tenant Client must be registered.');
        }

        $clients = [];
        foreach ($clientKeys as $key) {
            $client = new TenantClient($key);
            if (isset($clients[$client->key])) {
                throw new InvalidArgumentException("Duplicate Tenant Client: {$client->key}");
            }
            $clients[$client->key] = $client;
        }
        $this->clients = $clients;
    }

    public static function adminWeb(): self
    {
        return new self(['admin-web']);
    }

    public function require(string $key): TenantClient
    {
        return $this->clients[$key] ?? throw new InvalidArgumentException("Unknown Tenant Client: {$key}");
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->clients);
    }
}
