<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Host;

use DateTimeImmutable;
use DateTimeZone;
use PeanutAdmin\Kernel\Api\RequestId;
use PeanutAdmin\Kernel\Idempotency\CanonicalRequestHasher;

final readonly class ExternalOperationRequest
{
    public string $method;
    public DateTimeImmutable $comparisonTime;
    public DateTimeImmutable $idempotencyExpiresAt;

    /**
     * @param array<string, mixed> $body
     * @param list<array<string, mixed>> $typedTargets
     */
    public function __construct(
        public RequestId $requestId,
        public mixed $context,
        string $method,
        public string $path,
        public array $body,
        public array $typedTargets,
        public ?string $idempotencyKey,
        DateTimeImmutable $comparisonTime,
        DateTimeImmutable $idempotencyExpiresAt,
    ) {
        $this->method = strtoupper($method);
        $utc = new DateTimeZone('UTC');
        $this->comparisonTime = $comparisonTime->setTimezone($utc);
        $this->idempotencyExpiresAt = $idempotencyExpiresAt->setTimezone($utc);
    }

    public function requestHash(): string
    {
        return (new CanonicalRequestHasher())->hash($this->method, $this->path, [
            'body' => $this->body,
            'typed_targets' => $this->typedTargets,
        ]);
    }
}
