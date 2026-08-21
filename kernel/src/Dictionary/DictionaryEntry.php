<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Dictionary;

final readonly class DictionaryEntry
{
    public function __construct(
        public int $id,
        public string $name,
        public string $value,
        public int $typeId = 0,
        public string $type = '',
        public int $sort = 0,
        public bool $disabled = false,
        public string $remark = '',
        public string $source = 'tenant',
        /** @var array<string,mixed> */
        private array $attributes = [],
    ) {}

    /** @param array<string,mixed> $row */
    public static function fromArray(array $row, string $source = 'tenant'): self
    {
        return new self(
            (int) ($row['id'] ?? 0),
            (string) ($row['name'] ?? ''),
            (string) ($row['value'] ?? ''),
            (int) ($row['type_id'] ?? 0),
            (string) ($row['type'] ?? ($row['type_value'] ?? '')),
            (int) ($row['sort'] ?? 0),
            (int) ($row['is_disable'] ?? 0) !== 0,
            (string) ($row['remark'] ?? ''),
            $source,
            $row,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        if ($this->attributes !== []) {
            $values = $this->attributes;
            foreach (['id' => $this->id, 'name' => $this->name, 'value' => $this->value, 'type_id' => $this->typeId, 'type_value' => $this->type, 'sort' => $this->sort, 'is_disable' => $this->disabled ? 1 : 0, 'remark' => $this->remark, 'source' => $this->source] as $key => $value) {
                if (array_key_exists($key, $values)) {
                    $values[$key] = $value;
                }
            }
            return $values;
        }
        $values = [
            'id' => $this->id,
            'name' => $this->name,
            'value' => $this->value,
            'type_id' => $this->typeId,
            'type_value' => $this->type,
            'sort' => $this->sort,
            'is_disable' => $this->disabled ? 1 : 0,
            'remark' => $this->remark,
        ];
        if (array_key_exists('source', $this->attributes) || $this->source !== 'tenant') {
            $values['source'] = $this->source;
        }
        return $values;
    }
}
