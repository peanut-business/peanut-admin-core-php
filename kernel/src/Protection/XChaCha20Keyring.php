<?php

declare(strict_types=1);

namespace PeanutAdmin\Kernel\Protection;

use RuntimeException;
use Throwable;

/** Shared XChaCha20-Poly1305-IETF keyring for field and secret protection. */
final readonly class XChaCha20Keyring
{
    public const MAX_PLAINTEXT_BYTES = 4096;
    public const MAX_CIPHERTEXT_BYTES = 8192;

    /** @var array<array-key, string> */
    private array $keys;

    /** @param array<array-key, mixed> $keys */
    public function __construct(
        #[\SensitiveParameter] array $keys,
        public string $activeKeyId,
    ) {
        if ($keys === [] || !array_key_exists($activeKeyId, $keys)) {
            throw new RuntimeException('KEYRING_UNAVAILABLE');
        }
        $validated = [];
        foreach ($keys as $keyId => $key) {
            if (preg_match('/^[A-Za-z0-9._-]{1,64}$/D', (string) $keyId) !== 1
                || !is_string($key)
                || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                throw new RuntimeException('KEYRING_UNAVAILABLE');
            }
            $validated[$keyId] = $key;
        }
        $this->keys = $validated;
    }

    /** @return array{ciphertext:string,nonce:string,key_id:string} */
    public function encrypt(#[\SensitiveParameter] string $plaintext, string $aad): array
    {
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $aad,
                $nonce,
                $this->keys[$this->activeKeyId],
            );
        } catch (Throwable $e) {
            throw new RuntimeException('KEYRING_ENCRYPT_FAILED', 0, $e);
        }
        if ($ciphertext === '' || strlen($ciphertext) > self::MAX_CIPHERTEXT_BYTES) {
            throw new RuntimeException('KEYRING_ENCRYPT_FAILED');
        }

        return ['ciphertext' => $ciphertext, 'nonce' => $nonce, 'key_id' => $this->activeKeyId];
    }

    public function decrypt(
        #[\SensitiveParameter] string $ciphertext,
        #[\SensitiveParameter] string $nonce,
        string $keyId,
        string $aad,
    ): string {
        $key = $this->keys[$keyId] ?? null;
        if (!is_string($key)
            || $ciphertext === ''
            || strlen($ciphertext) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES
            || strlen($ciphertext) > self::MAX_CIPHERTEXT_BYTES
            || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new RuntimeException('KEYRING_DECRYPT_FAILED');
        }
        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($ciphertext, $aad, $nonce, $key);
        } catch (Throwable $e) {
            throw new RuntimeException('KEYRING_DECRYPT_FAILED', 0, $e);
        }
        if (!is_string($plaintext) || $plaintext === '' || strlen($plaintext) > self::MAX_PLAINTEXT_BYTES) {
            throw new RuntimeException('KEYRING_DECRYPT_FAILED');
        }

        return $plaintext;
    }
}
