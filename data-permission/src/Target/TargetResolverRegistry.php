<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Target;

use PeanutAdmin\DataPermission\Exception\DataAuthorizationException;

final class TargetResolverRegistry
{
    /** @var array<string, ResourceTargetResolver> */
    private array $resolvers = [];

    public function register(string $key, ResourceTargetResolver $resolver): void
    {
        $this->resolvers[$key] = $resolver;
    }

    public function get(string $key): ResourceTargetResolver
    {
        return $this->resolvers[$key] ?? throw new DataAuthorizationException(
            'AUTHZ_PROVIDER_MISSING',
            "Target resolver {$key} is not registered.",
        );
    }
}
