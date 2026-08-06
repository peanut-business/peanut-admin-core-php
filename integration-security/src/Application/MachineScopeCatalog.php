<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Application;

final readonly class MachineScopeCatalog
{
    /** @var array<string, true> */
    private array $known;

    /** @param list<string> $scopes */
    public function __construct(array $scopes)
    {
        $known = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $scope) !== 1 || strlen($scope) > 96) {
                throw IntegrationSecurityException::invalid();
            }
            $known[$scope] = true;
        }
        if ($known === [] || count($known) > 128) {
            throw IntegrationSecurityException::invalid();
        }
        $this->known = $known;
    }

    public function contains(string $scope): bool
    {
        return isset($this->known[$scope]);
    }
}
