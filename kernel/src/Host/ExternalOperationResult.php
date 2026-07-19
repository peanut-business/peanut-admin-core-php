<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use InvalidArgumentException;

final readonly class ExternalOperationResult
{
    /** @var array<string, bool|int|string|null> */
    public array $auditMetadata;

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $auditMetadata
     */
    public function __construct(
        public int $status,
        public array $body,
        public string $auditEventType,
        public string $auditAction,
        array $auditMetadata = [],
        public ?string $resourceType = null,
        public ?string $resourceId = null,
    ) {
        if ($status < 200 || $status > 299) {
            throw new InvalidArgumentException('Atomic operation result must be successful.');
        }
        if (
            preg_match('/^[a-z][a-z0-9.-]{2,95}$/D', $auditEventType) !== 1
            || preg_match('/^[a-z][a-z0-9.-]{2,159}$/D', $auditAction) !== 1
        ) {
            throw new InvalidArgumentException('Invalid audit event or action.');
        }
        $normalized = [];
        foreach ($auditMetadata as $key => $value) {
            if (!is_bool($value) && !is_int($value) && !is_string($value) && $value !== null) {
                throw new InvalidArgumentException('Audit metadata must be scalar and redacted.');
            }
            $normalized[$key] = $value;
        }
        $this->auditMetadata = $normalized;
    }

    public function response(): ExternalOperationResponse
    {
        return new ExternalOperationResponse($this->status, $this->body);
    }
}
