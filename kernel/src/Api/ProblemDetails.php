<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Api;

final readonly class ProblemDetails
{
    /** @param list<array{pointer: string, code: string, message: string}> $errors */
    private function __construct(
        public string $type,
        public string $title,
        public int $status,
        public string $detail,
        public string $instance,
        public string $code,
        public string $requestId,
        public array $errors,
    ) {}

    public static function fromException(ApiException $exception, RequestId $requestId): self
    {
        $slug = strtolower(str_replace('_', '-', $exception->errorCode));
        $title = match ($exception->httpStatus) {
            404 => 'Resource not found',
            422 => 'Validation failed',
            default => 'Request rejected',
        };

        return new self(
            '/docs/problems/' . $slug,
            $title,
            $exception->httpStatus,
            $exception->getMessage(),
            'urn:request:' . $requestId->value,
            $exception->errorCode,
            $requestId->value,
            $exception->errors,
        );
    }

    public function contentType(): string
    {
        return 'application/problem+json';
    }

    /** @return array<string, int|string|list<array{pointer: string, code: string, message: string}>> */
    public function toArray(): array
    {
        $payload = [
            'type' => $this->type,
            'title' => $this->title,
            'status' => $this->status,
            'detail' => $this->detail,
            'instance' => $this->instance,
            'code' => $this->code,
            'request_id' => $this->requestId,
        ];
        if ($this->errors !== []) {
            $payload['errors'] = $this->errors;
        }

        return $payload;
    }
}
