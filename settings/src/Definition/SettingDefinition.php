<?php

declare(strict_types=1);

namespace PeanutAdmin\Settings\Definition;

use Opis\JsonSchema\Validator;
use PeanutAdmin\Settings\Application\SettingException;
use Throwable;

final readonly class SettingDefinition
{
    /** @param array<string, mixed> $schema
     * @param non-empty-list<string> $allowedScopes
     */
    public function __construct(
        public string $moduleKey,
        public string $key,
        public string $name,
        public string $description,
        public array $schema,
        public bool $required,
        public bool $secret,
        public array $allowedScopes,
        public ?string $targetResourceKey,
        public ?string $targetOperation,
        public bool $hasDefault,
        public mixed $defaultValue,
        public string $digest,
    ) {}

    public function qualifiedKey(): string
    {
        return $this->moduleKey . ':' . $this->key;
    }

    public function allows(string $scope): bool
    {
        return in_array($scope, $this->allowedScopes, true);
    }

    public function assertValue(mixed $value, string $errorCode = 'SETTING_VALUE_INVALID'): void
    {
        try {
            $schema = json_decode(json_encode($this->schema, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            $result = (new Validator())->validate($value, $schema);
        } catch (Throwable $exception) {
            throw SettingException::invalid($errorCode, 'The setting value does not match its trusted schema.');
        }
        if (!$result->isValid()) {
            throw SettingException::invalid($errorCode, 'The setting value does not match its trusted schema.');
        }
    }
}
