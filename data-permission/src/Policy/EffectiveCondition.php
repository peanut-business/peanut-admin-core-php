<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Policy;

final readonly class EffectiveCondition
{
    /** @param list<string> $targetIds */
    public function __construct(
        public int $id,
        public string $key,
        public ?int $targetSetId,
        public ?string $targetResourceKey,
        public array $targetIds,
        public int $targetCount,
    ) {}
}
