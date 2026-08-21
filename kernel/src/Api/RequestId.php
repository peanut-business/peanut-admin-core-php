<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Api;

final readonly class RequestId
{
    private function __construct(public string $value) {}

    public static function fromHeader(?string $header): self
    {
        if (is_string($header) && preg_match('/^[A-Za-z0-9._:-]{8,64}$/D', $header) === 1) {
            return new self($header);
        }

        return new self('req_' . bin2hex(random_bytes(16)));
    }
}
