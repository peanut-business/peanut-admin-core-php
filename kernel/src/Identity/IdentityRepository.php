<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Identity;

interface IdentityRepository
{
    public function createAccount(string $displayName): AccountRecord;

    public function accountById(int $accountId, bool $forUpdate = false): ?AccountRecord;

    public function transitionAccount(int $accountId, AccountStatus $next): AccountRecord;

    public function credentialByEmail(EmailAddress $email, bool $forUpdate = false): ?CredentialRecord;

    public function activeCredentialForAccount(int $accountId, bool $forUpdate = false): ?CredentialRecord;

    public function createEmailCredential(
        int $accountId,
        EmailAddress $email,
        string $secretHash,
    ): CredentialRecord;

    public function transitionCredential(int $credentialId, CredentialStatus $next): CredentialRecord;
}
