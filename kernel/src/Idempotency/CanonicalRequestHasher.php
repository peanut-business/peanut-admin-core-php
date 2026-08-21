<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Idempotency;

use JsonException;

final class CanonicalRequestHasher
{
    /** @param array<string, mixed> $body */
    public function hash(string $method, string $path, array $body): string
    {
        try {
            $payload = json_encode([
                'method' => strtoupper($method),
                'path' => $path,
                'body' => $this->normalize($body),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('Request body cannot be canonicalized.', 0, $exception);
        }

        return hash('sha256', $payload);
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->normalize(...), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
