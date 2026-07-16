<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Module;

use JsonException;

final readonly class ManifestDocument
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public string $root,
        public array $data,
        public object $object,
        public string $digest,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(string $root, array $data): self
    {
        try {
            $canonical = self::canonicalize($data);
            $json = json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $object = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', 'Manifest is not valid JSON: ' . $exception->getMessage());
        }

        if (!is_object($object)) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', 'Manifest root must be an object.');
        }

        return new self(rtrim($root, '/'), $data, $object, hash('sha256', $json));
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function canonicalize(array $value): array
    {
        if (array_is_list($value)) {
            return array_map(
                static fn(mixed $item): mixed => is_array($item) ? self::canonicalize($item) : $item,
                $value,
            );
        }

        ksort($value);
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::canonicalize($item);
            }
        }

        return $value;
    }
}
