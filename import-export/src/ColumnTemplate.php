<?php

declare(strict_types=1);

namespace PeanutAdmin\ImportExport;

final readonly class ColumnTemplate
{
    /** @param array<string,callable(mixed):(?string)> $columns Column names mapped to validators returning an error or null. */
    public function __construct(public array $columns) {}

    /**
     * @param array<string,mixed> $row
     * @return array<string,string>
     */
    public function validate(array $row): array
    {
        $errors = [];
        foreach ($this->columns as $name => $validate) {
            $error = $validate($row[$name] ?? null);
            if ($error !== null) {
                $errors[$name] = $error;
            }
        }
        return $errors;
    }
}
