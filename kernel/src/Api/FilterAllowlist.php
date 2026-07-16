<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Api;

final readonly class FilterAllowlist
{
    /**
     * @param list<string> $filterFields
     * @param list<string> $sortFields
     * @param list<string> $includes
     */
    public function __construct(
        private array $filterFields,
        private array $sortFields,
        private array $includes,
    ) {}

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function filters(array $input): array
    {
        foreach (array_keys($input) as $field) {
            if (!in_array($field, $this->filterFields, true)) {
                throw $this->unknown('/query/' . $field, 'FILTER_NOT_ALLOWED');
            }
        }

        return $input;
    }

    /** @return list<array{string, 'asc'|'desc'}> */
    public function sort(string $input): array
    {
        if ($input === '') {
            return [];
        }
        $result = [];
        foreach (explode(',', $input) as $term) {
            $direction = str_starts_with($term, '-') ? 'desc' : 'asc';
            $field = ltrim($term, '-');
            if (!in_array($field, $this->sortFields, true)) {
                throw $this->unknown('/query/sort', 'SORT_NOT_ALLOWED');
            }
            $result[] = [$field, $direction];
        }

        return $result;
    }

    /** @return list<string> */
    public function includes(string $input): array
    {
        $values = $input === '' ? [] : explode(',', $input);
        foreach ($values as $value) {
            if (!in_array($value, $this->includes, true)) {
                throw $this->unknown('/query/include', 'INCLUDE_NOT_ALLOWED');
            }
        }

        return $values;
    }

    private function unknown(string $pointer, string $code): ApiException
    {
        return new ApiException('VALIDATION_FAILED', 422, 'One or more fields are invalid.', [[
            'pointer' => $pointer,
            'code' => $code,
            'message' => 'The requested query field is not available.',
        ]]);
    }
}
