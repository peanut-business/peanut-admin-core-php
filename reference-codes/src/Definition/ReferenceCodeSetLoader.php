<?php

declare(strict_types=1);

namespace PeanutAdmin\ReferenceCodes\Definition;

use JsonException;
use PeanutAdmin\Kernel\Module\ModuleKey;
use PeanutAdmin\ReferenceCodes\Application\ReferenceCodeException;
use Throwable;

final class ReferenceCodeSetLoader
{
    private const FIELDS = ['key', 'name', 'description'];

    /** @return list<ReferenceCodeSetDefinition> */
    public function load(string $declaringModuleKey, string $resourcePath): array
    {
        try {
            ModuleKey::fromString($declaringModuleKey);
        } catch (Throwable) {
            throw ReferenceCodeException::invalid(
                'REFERENCE_CODE_SET_OWNER_MISMATCH',
                'The declaring Module key is invalid.',
            );
        }
        if (!is_file($resourcePath) || !is_readable($resourcePath)) {
            throw ReferenceCodeException::invalid(
                'REFERENCE_CODE_SET_RESOURCE_MISSING',
                'The reference-code set resource is missing.',
            );
        }
        try {
            $decoded = json_decode((string) file_get_contents($resourcePath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ReferenceCodeException::invalid(
                'REFERENCE_CODE_SET_DEFINITION_INVALID',
                'The reference-code set resource is invalid JSON.',
            );
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            throw ReferenceCodeException::invalid(
                'REFERENCE_CODE_SET_DEFINITION_INVALID',
                'The reference-code set resource must be a list.',
            );
        }

        $definitions = [];
        $seen = [];
        foreach ($decoded as $input) {
            if (!is_array($input) || array_is_list($input)
                || array_diff(array_keys($input), self::FIELDS) !== []
                || array_diff(self::FIELDS, array_keys($input)) !== []) {
                throw ReferenceCodeException::invalid(
                    'REFERENCE_CODE_SET_DEFINITION_INVALID',
                    'A reference-code set has missing or unknown fields.',
                );
            }
            $key = $this->text($input['key'], 64, false);
            if (preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $key) !== 1) {
                throw ReferenceCodeException::invalid(
                    'REFERENCE_CODE_SET_DEFINITION_INVALID',
                    'The local reference-code set key is invalid.',
                );
            }
            if (isset($seen[$key])) {
                throw ReferenceCodeException::invalid(
                    'REFERENCE_CODE_SET_DUPLICATE',
                    'A local reference-code set key is duplicated.',
                );
            }
            $seen[$key] = true;
            $name = $this->text($input['name'], 160, true);
            $description = $this->text($input['description'], 500, true);
            $canonical = [
                'description' => $description,
                'key' => $key,
                'name' => $name,
            ];
            try {
                $json = json_encode(
                    $canonical,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                );
            } catch (JsonException) {
                throw ReferenceCodeException::invalid(
                    'REFERENCE_CODE_SET_DEFINITION_INVALID',
                    'The reference-code set cannot be canonicalized.',
                );
            }
            $definitions[] = new ReferenceCodeSetDefinition(
                $declaringModuleKey,
                $key,
                $name,
                $description,
                hash('sha256', $json),
            );
        }

        return $definitions;
    }

    private function text(mixed $value, int $maximum, bool $trim): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1) {
            throw ReferenceCodeException::invalid(
                'REFERENCE_CODE_SET_DEFINITION_INVALID',
                'A reference-code set display field is invalid.',
            );
        }
        $value = $trim ? trim($value) : $value;
        if ($value === '' || $this->unicodeLength($value) > $maximum) {
            throw ReferenceCodeException::invalid(
                'REFERENCE_CODE_SET_DEFINITION_INVALID',
                'A reference-code set display field is invalid.',
            );
        }

        return $value;
    }

    private function unicodeLength(string $value): int
    {
        $count = preg_match_all('/./us', $value, $matches);

        return is_int($count) ? $count : PHP_INT_MAX;
    }
}
