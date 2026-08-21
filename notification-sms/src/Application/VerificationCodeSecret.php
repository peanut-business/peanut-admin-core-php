<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Application;

/** Stores verification codes as non-replayable slow hashes. */
final class VerificationCodeSecret
{
    public static function hash(string $code): string
    {
        if (preg_match('/^\d{4}$/D', $code) !== 1) {
            throw new \InvalidArgumentException('The verification code format is invalid.');
        }
        $hash = password_hash($code, PASSWORD_DEFAULT);
        if (!is_string($hash)) {
            throw new \RuntimeException('The verification code hash could not be created.');
        }

        return $hash;
    }

    public static function matches(string $code, string $hash): bool
    {
        return preg_match('/^\d{4}$/D', $code) === 1
            && $hash !== ''
            && password_verify($code, $hash);
    }
}
