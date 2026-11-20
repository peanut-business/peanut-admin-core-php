<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Support;

use PeanutAdmin\Kernel\Api\RequestId;
use PeanutAdmin\Kernel\Module\ModuleExecutionContext;

/** Correlation only; this value is never an authorization identity. */
final readonly class TraceId
{
    private function __construct(public string $value) {}

    public static function acceptsHeaderValue(string $trimmed): bool
    {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,63}$/D', $trimmed) === 1;
    }

    public static function generate(string $prefix): string
    {
        $prefix = strtolower(trim($prefix));
        if (preg_match('/^[a-z][a-z0-9-]{0,15}$/D', $prefix) !== 1) {
            throw new \InvalidArgumentException('REQUEST_TRACE_PREFIX_INVALID');
        }
        return $prefix . '-' . bin2hex(random_bytes(16));
    }

    public static function fromRequestId(RequestId $id): self
    {
        return new self($id->value);
    }

    public static function fromExecutionContext(ModuleExecutionContext $context): self
    {
        return new self($context->operationId);
    }
}
