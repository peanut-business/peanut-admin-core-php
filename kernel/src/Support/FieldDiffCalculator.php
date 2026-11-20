<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Support;

final class FieldDiffCalculator
{
    /**
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,array{before:mixed,after:mixed}>
     */
    public function calculate(array $before, array $after): array
    {
        $result = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            if (array_key_exists($field, $before) !== array_key_exists($field, $after) || ($before[$field] ?? null) !== ($after[$field] ?? null)) {
                $result[$field] = ['before' => $before[$field] ?? null, 'after' => $after[$field] ?? null];
            }
        }
        return $result;
    }
}
