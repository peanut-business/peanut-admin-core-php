<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Support;

final class DataMasker
{
    public function phone(string $value, bool $reveal = false): string
    {
        return $reveal ? $value : $this->partial($value, 3, 4);
    }

    public function email(string $value, bool $reveal = false): string
    {
        if ($reveal) {
            return $value;
        }
        $parts = explode('@', $value, 2);
        return $this->partial($parts[0], 1, 0) . (isset($parts[1]) ? '@' . $parts[1] : '');
    }

    public function identity(string $value, bool $reveal = false): string
    {
        return $reveal ? $value : $this->partial($value, 3, 4);
    }

    public function name(string $value, bool $reveal = false): string
    {
        return $reveal ? $value : $this->partial($value, 1, 0);
    }

    private function partial(string $value, int $start, int $end): string
    {
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw new \InvalidArgumentException('Value must be valid UTF-8.');
        }
        if (count($characters) <= $start + $end) {
            return $this->mask($value);
        }
        return $this->mask($value, $start, $end);
    }

    public function mask(string $value, int $visibleStart = 0, int $visibleEnd = 0, string $replacement = '*'): string
    {
        if ($visibleStart < 0 || $visibleEnd < 0 || $replacement === '') {
            throw new \InvalidArgumentException('Invalid masking options.');
        }
        $characters = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
        if ($characters === false) {
            throw new \InvalidArgumentException('Value must be valid UTF-8.');
        }
        $length = count($characters);
        $start = min($visibleStart, $length);
        $end = min($visibleEnd, $length - $start);
        return implode('', array_slice($characters, 0, $start)) . str_repeat($replacement, $length - $start - $end) . ($end ? implode('', array_slice($characters, -$end)) : '');
    }
}
