<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Sms;

/**
 * Framework-neutral base for providers that send a configured SMS template.
 *
 * Concrete providers own their credential format and HTTP transport. Hosts retain
 * responsibility for resolving and storing those credentials.
 */
abstract class TemplateSmsDriver
{
    protected string $error = '';

    /** @var array<string, mixed> */
    protected array $result = [];

    /** @param array<string, mixed> $config */
    abstract public function __construct(array $config);

    /**
     * @param array<string, mixed> $variables
     */
    abstract public function send(string $mobile, string $templateCode, array $variables): bool;

    public function getError(): string
    {
        return $this->error;
    }

    /** @return array<string, mixed> */
    public function getResult(): array
    {
        return $this->result;
    }
}
