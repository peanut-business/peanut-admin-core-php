<?php

declare(strict_types=1);

namespace PeanutAdmin\IntegrationSecurity\Webhook;

use PeanutAdmin\IntegrationSecurity\Application\IntegrationSecurityException;

final readonly class TrustedWebhookEvent
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $eventKey,
        public string $eventType,
        public array $payload,
    ) {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}$/D', $eventKey) !== 1
            || preg_match('/^[a-z][a-z0-9]*(?:[.-][a-z0-9]+)+$/D', $eventType) !== 1
        ) {
            throw IntegrationSecurityException::invalid();
        }
        $json = json_encode(self::normalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($json) > 262144) {
            throw IntegrationSecurityException::invalid();
        }
    }

    public function canonicalPayload(): string
    {
        return json_encode(self::normalize($this->payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 32) {
            throw IntegrationSecurityException::invalid();
        }
        if (!is_array($value)) {
            if ($value === null || is_string($value) || is_int($value) || is_bool($value) || (is_float($value) && is_finite($value))) {
                return $value;
            }
            throw IntegrationSecurityException::invalid();
        }
        if (array_is_list($value)) {
            return array_map(static fn(mixed $item): mixed => self::normalize($item, $depth + 1), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            if (!is_string($key) || $key === '' || strlen($key) > 128) {
                throw IntegrationSecurityException::invalid();
            }
            $value[$key] = self::normalize($item, $depth + 1);
        }
        return $value;
    }
}
