<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\State;

final readonly class StateMachine
{
    /** @param array<string,list<string>> $transitions */
    public function __construct(private array $transitions) {}

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, $this->transitions[$from] ?? [], true);
    }

    public function transition(string $from, string $to): string
    {
        if (!$this->canTransition($from, $to)) {
            throw new \DomainException('State transition is not allowed.');
        }
        return $to;
    }
}
