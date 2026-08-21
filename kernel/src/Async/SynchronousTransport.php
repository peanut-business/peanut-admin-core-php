<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Async;

use RuntimeException;

final class SynchronousTransport
{
    /** @var list<string> */
    private array $messages = [];

    public function send(string $encodedEnvelope): void
    {
        $this->messages[] = $encodedEnvelope;
    }

    public function receive(): string
    {
        $message = array_shift($this->messages);
        if ($message === null) {
            throw new RuntimeException('Synchronous transport is empty.');
        }

        return $message;
    }
}
