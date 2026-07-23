<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Audit;

use InvalidArgumentException;

final class GovernanceAuditMetadata
{
    /** @var array<string, true> */
    private array $allowed = [];

    /** @param list<string> $allowedKeys */
    public function __construct(array $allowedKeys)
    {
        foreach ($allowedKeys as $key) {
            if (preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1
                || preg_match('/token|secret|cookie|password|sql|target_set/i', $key) === 1) {
                throw new InvalidArgumentException('AUDIT_METADATA_ALLOWLIST_INVALID');
            }
            $this->allowed[$key] = true;
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, bool|int|string|null>
     */
    public function project(array $metadata): array
    {
        $safe = [];
        foreach ($metadata as $key => $value) {
            if (!isset($this->allowed[$key]) || !(is_bool($value) || is_int($value) || is_string($value) || $value === null)) {
                continue;
            }
            $safe[$key] = $value;
        }
        ksort($safe);

        return $safe;
    }
}
