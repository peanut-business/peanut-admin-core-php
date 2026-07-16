<?php

declare(strict_types=1);

namespace PeanutAdmin\DataPermission\Policy;

use DateTimeImmutable;

final readonly class PolicyRevision
{
    public function __construct(public string $value, public ?DateTimeImmutable $nextTransition) {}
}
