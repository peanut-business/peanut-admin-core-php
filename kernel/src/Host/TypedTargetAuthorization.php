<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use PeanutAdmin\Kernel\Context\RequestedTargetSet;

/** Typed-target result has its own PSR-4 entry; loading it never depends on loading the adapter first. */
final readonly class TypedTargetAuthorization
{
    /** @param list<RequestedTargetSet> $targets */
    public function __construct(
        public ?object $queryConstraint,
        public array $targets,
    ) {}
}
