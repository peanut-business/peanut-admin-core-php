<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Policy;

use DateTimeImmutable;

final class PolicyCache
{
    /** @var array<string, array{expires: int, policies: EffectivePolicySet}> */
    private array $entries = [];

    public function get(string $key): ?EffectivePolicySet
    {
        $entry = $this->entries[$key] ?? null;
        if ($entry === null || $entry['expires'] <= time()) {
            unset($this->entries[$key]);

            return null;
        }

        return $entry['policies'];
    }

    public function put(string $key, EffectivePolicySet $policies, ?DateTimeImmutable $nextTransition): void
    {
        $expires = time() + 300;
        if ($nextTransition !== null) {
            $expires = min($expires, $nextTransition->getTimestamp());
        }
        $this->entries[$key] = ['expires' => max(time() + 1, $expires), 'policies' => $policies];
    }
}
