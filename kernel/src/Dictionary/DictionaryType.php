<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Dictionary;

final readonly class DictionaryType
{
    public function __construct(
        public int $id,
        public string $name,
        public string $type,
        public bool $disabled,
        public string $remark = '',
        /** @var array<string,mixed> */
        private array $attributes = [],
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['name'] ?? ''),
            (string) ($row['type'] ?? ''),
            (int) ($row['is_disable'] ?? 0) !== 0,
            (string) ($row['remark'] ?? ''),
            $row,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        if ($this->attributes !== []) {
            $values = $this->attributes;
            foreach (['id' => $this->id, 'name' => $this->name, 'type' => $this->type, 'is_disable' => $this->disabled ? 1 : 0, 'remark' => $this->remark] as $key => $value) {
                if (array_key_exists($key, $values)) {
                    $values[$key] = $value;
                }
            }
            return $values;
        }
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'is_disable' => $this->disabled ? 1 : 0,
            'remark' => $this->remark,
        ];
    }
}
