<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Provider;

use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;

final class ConditionProviderRegistry
{
    /** @var array<string, ConditionProvider> */
    private array $providers = [];

    public function register(string $conditionKey, ConditionProvider $provider): void
    {
        $this->providers[$conditionKey] = $provider;
    }

    public function get(string $conditionKey): ConditionProvider
    {
        return $this->providers[$conditionKey] ?? throw new DataAuthorizationException(
            'AUTHZ_CONDITION_UNSUPPORTED',
            "Condition {$conditionKey} is not registered.",
        );
    }
}
