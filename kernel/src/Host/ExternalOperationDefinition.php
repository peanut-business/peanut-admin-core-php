<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use InvalidArgumentException;
use PeanutAdmin\Kernel\Authorization\PermissionRequirement;
use PeanutAdmin\Kernel\Module\ModuleKey;

final readonly class ExternalOperationDefinition
{
    public string $method;

    public function __construct(
        public string $operationId,
        string $method,
        public string $path,
        public string $audience,
        public string $moduleKey,
        public PermissionRequirement $permission,
        public ?string $resourceKey = null,
        public string $dataAuthorization = 'none',
        public string $targetCardinality = 'none',
        public bool $atomicCommand = false,
        public bool $idempotencyRequired = false,
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9]{2,95}$/D', $operationId) !== 1) {
            throw new InvalidArgumentException('Invalid operation ID.');
        }
        $this->method = strtoupper($method);
        if (!in_array($this->method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw new InvalidArgumentException('Unsupported operation method.');
        }
        $this->assertPath($path);
        ModuleKey::fromString($moduleKey);
        if (!in_array($audience, ['tenant', 'platform'], true) || $permission->audience !== $audience) {
            throw new InvalidArgumentException('Operation and permission audiences must match.');
        }
        if (!in_array($dataAuthorization, ['none', 'query', 'targets'], true)) {
            throw new InvalidArgumentException('Invalid data authorization mode.');
        }
        if (!in_array($targetCardinality, ['none', 'one_required', 'zero_or_one', 'many_readable'], true)) {
            throw new InvalidArgumentException('Invalid target cardinality.');
        }
        if ($dataAuthorization === 'none' && ($resourceKey !== null || $targetCardinality !== 'none')) {
            throw new InvalidArgumentException('Target-free operations cannot declare a resource or cardinality.');
        }
        if ($dataAuthorization !== 'none' && ($resourceKey === null || $resourceKey === '' || $targetCardinality === 'none')) {
            throw new InvalidArgumentException('Data-authorized operations require a resource and cardinality.');
        }
        if ($audience === 'platform' && $dataAuthorization !== 'none') {
            throw new InvalidArgumentException('Platform operations cannot use Tenant typed targets.');
        }
        if ($atomicCommand && $this->method === 'GET') {
            throw new InvalidArgumentException('Read operations cannot be atomic commands.');
        }
        if ($idempotencyRequired && !$atomicCommand) {
            throw new InvalidArgumentException('Idempotency requires an atomic command.');
        }
    }

    public function matches(string $method, string $path): bool
    {
        if (strtoupper($method) !== $this->method || str_contains($path, '?') || str_contains($path, '#')) {
            return false;
        }
        $expected = explode('/', trim($this->path, '/'));
        $actual = explode('/', trim($path, '/'));
        if (count($expected) !== count($actual)) {
            return false;
        }
        foreach ($expected as $index => $segment) {
            if (preg_match('/^\{[a-z][a-z0-9_]*\}$/D', $segment) === 1) {
                if ($actual[$index] === '') {
                    return false;
                }
                continue;
            }
            if (!hash_equals($segment, $actual[$index])) {
                return false;
            }
        }

        return true;
    }

    private function assertPath(string $path): void
    {
        if ($path === '/' || str_ends_with($path, '/') || !str_starts_with($path, '/')) {
            throw new InvalidArgumentException('Invalid operation path.');
        }
        foreach (explode('/', substr($path, 1)) as $segment) {
            if (
                preg_match('/^[A-Za-z0-9._~-]+$/D', $segment) !== 1
                && preg_match('/^\{[a-z][a-z0-9_]*\}$/D', $segment) !== 1
            ) {
                throw new InvalidArgumentException('Invalid operation path segment.');
            }
        }
    }
}
