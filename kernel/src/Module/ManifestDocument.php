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

    /**
     * The decoded JSON object is authoritative; arrays for compilation are derived here.
     * Object/array distinctions remain intact for schema validation and the digest.
     */
    public static function fromObject(string $root, object $object): self
    {
        try {
            $canonicalObject = self::canonicalizeJsonValue($object);
            $json = json_encode($canonicalObject, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new ModuleException('MODULE_MANIFEST_INVALID', 'Manifest is not valid JSON: ' . $exception->getMessage());
        }

        return new self(rtrim($root, '/'), self::arrayValue($canonicalObject), $canonicalObject, hash('sha256', $json));
    }

    /**
     * @param array<array-key, mixed> $value
     * @return array<array-key, mixed>
     */
    private static function arrayValue(array|object $value): array
    {
        $result = [];
        foreach ($value as $key => $item) {
            $result[$key] = is_array($item) || is_object($item) ? self::arrayValue($item) : $item;
        }
        return $result;
    }

    private static function canonicalizeJsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(self::canonicalizeJsonValue(...), $value);
        }
        if (!is_object($value)) {
            return $value;
        }

        $properties = get_object_vars($value);
        ksort($properties);
        $canonical = new \stdClass();
        foreach ($properties as $key => $item) {
            $canonical->{$key} = self::canonicalizeJsonValue($item);
        }

        return $canonical;
    }
}
