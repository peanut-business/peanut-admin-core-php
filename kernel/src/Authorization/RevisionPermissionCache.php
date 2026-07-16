<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Authorization;

final class RevisionPermissionCache
{
    /** @var array<string, EffectivePermissionSet> */
    private array $entries = [];

    public function __construct(private readonly int $maximumEntries = 2048) {}

    public function get(string $audience, string $principalKey, string $revision): ?EffectivePermissionSet
    {
        return $this->entries[$this->key($audience, $principalKey, $revision)] ?? null;
    }

    public function put(
        string $audience,
        string $principalKey,
        string $revision,
        EffectivePermissionSet $permissions,
    ): void {
        if (count($this->entries) >= $this->maximumEntries) {
            array_shift($this->entries);
        }

        $this->entries[$this->key($audience, $principalKey, $revision)] = $permissions;
    }

    private function key(string $audience, string $principalKey, string $revision): string
    {
        return "authz:{$audience}:{$principalKey}:revision:{$revision}";
    }
}
