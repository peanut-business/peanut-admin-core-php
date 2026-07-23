<?php

declare(strict_types=1);

namespace PeanutAdmin\NotificationSms\Application;

final readonly class RecipientSnapshot
{
    public function __construct(
        public int $memberId,
        public int $accountId,
        public string $displayName,
        public ?string $phoneMasked,
        public ?string $phoneDigest,
    ) {
        if ($memberId < 1 || $accountId < 1 || $displayName === '' || mb_strlen($displayName) > 160
            || str_contains($displayName, "\0")
            || (($phoneMasked === null) !== ($phoneDigest === null))
            || ($phoneMasked !== null && preg_match('/^\+[0-9]{1,3}\*{4,12}[0-9]{2,4}$/D', $phoneMasked) !== 1)
            || ($phoneDigest !== null && preg_match('/^[0-9a-f]{64}$/D', $phoneDigest) !== 1)
        ) {
            throw NotificationException::recipientUnavailable();
        }
    }
}
