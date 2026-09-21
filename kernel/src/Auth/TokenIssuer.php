<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Auth;

use DateTimeImmutable;

final class TokenIssuer
{
    public const CHALLENGE_PREFIX = 'pa_lc_';
    public const TENANT_ACCESS_PREFIX = 'pa_tat_';
    public const TENANT_REFRESH_PREFIX = 'pa_trt_';
    public const PLATFORM_ACCESS_PREFIX = 'pa_pat_';
    public const PLATFORM_REFRESH_PREFIX = 'pa_prt_';

    private const ULID_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function challenge(): RawToken
    {
        return $this->opaque(self::CHALLENGE_PREFIX);
    }

    public function tenantAccess(): RawToken
    {
        return $this->opaque(self::TENANT_ACCESS_PREFIX);
    }

    public function tenantRefresh(): RawToken
    {
        return $this->opaque(self::TENANT_REFRESH_PREFIX);
    }

    public function platformAccess(): RawToken
    {
        return $this->opaque(self::PLATFORM_ACCESS_PREFIX);
    }

    public function platformRefresh(): RawToken
    {
        return $this->opaque(self::PLATFORM_REFRESH_PREFIX);
    }

    public function key(DateTimeImmutable $time): string
    {
        $milliseconds = ((int) $time->format('U')) * 1000 + (int) $time->format('v');
        $bytes = substr(pack('J', $milliseconds), 2) . random_bytes(10);
        $bits = '00';
        foreach (unpack('C*', $bytes) ?: [] as $byte) {
            $bits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }

        $result = '';
        for ($offset = 0; $offset < 130; $offset += 5) {
            $result .= self::ULID_ALPHABET[(int) bindec(substr($bits, $offset, 5))];
        }

        return $result;
    }

    private function opaque(string $prefix): RawToken
    {
        $encoded = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        return new RawToken($prefix . $encoded);
    }
}
