<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Application;

use PeanutAdmin\Kernel\Context\AuthorizedOperationContext;

final readonly class MachineScopeGrantPolicy
{
    public function __construct(
        private MachineScopeCatalog $catalog,
        private MachineScopeGrantResolver $resolver,
    ) {}

    /** @param list<string> $requested */
    public function assertGrantable(AuthorizedOperationContext $context, array $requested): void
    {
        $grantable = [];
        foreach ($this->resolver->grantableScopes($context) as $scope) {
            if (!$this->valid($scope) || !$this->catalog->contains($scope)) {
                throw IntegrationSecurityException::scopeDenied();
            }
            $grantable[$scope] = true;
        }
        foreach ($requested as $scope) {
            if (!$this->catalog->contains($scope)) {
                throw IntegrationSecurityException::invalid();
            }
            if (!isset($grantable[$scope])) {
                throw IntegrationSecurityException::scopeDenied();
            }
        }
    }

    /** @param list<string> $required */
    public function assertKnown(array $required): void
    {
        foreach ($required as $scope) {
            if (!$this->valid($scope) || !$this->catalog->contains($scope)) {
                throw IntegrationSecurityException::scopeDenied();
            }
        }
    }

    /** @param list<string> $persisted */
    public function assertPersisted(array $persisted): void
    {
        if ($persisted === [] || count($persisted) > 32) {
            throw IntegrationSecurityException::scopeDenied();
        }
        $normalized = [];
        foreach ($persisted as $scope) {
            if (!$this->valid($scope) || !$this->catalog->contains($scope) || isset($normalized[$scope])) {
                throw IntegrationSecurityException::scopeDenied();
            }
            $normalized[$scope] = true;
        }
        $expected = array_keys($normalized);
        sort($expected, SORT_STRING);
        if ($persisted !== $expected) {
            throw IntegrationSecurityException::scopeDenied();
        }
    }

    private function valid(mixed $scope): bool
    {
        return is_string($scope)
            && preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $scope) === 1
            && strlen($scope) <= 96;
    }
}
