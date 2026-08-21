<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Identity;

final readonly class CredentialRecord
{
    public function __construct(
        public int $id,
        public int $accountId,
        public EmailAddress $email,
        public string $secretHash,
        public CredentialStatus $status,
        public int $revision,
    ) {}
}
