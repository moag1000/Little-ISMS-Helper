<?php

namespace App\Service;

use RuntimeException;

/**
 * AES-256-GCM encryption/decryption for sensitive backup fields.
 *
 * The encryption key is derived from APP_SECRET via SHA-256 (32 bytes).
 * Encrypted values are stored as a JSON-serialisable envelope so they
 * survive JSON round-trips transparently inside backup archives.
 */
class BackupEncryptionService
{
    private const CIPHER = 'aes-256-gcm';

    /** Marker key present in every encrypted envelope. */
    private const MARKER = '__encrypted';

    private readonly string $key;

    public function __construct(string $appSecret)
    {
        // Derive a 32-byte key from APP_SECRET so the key length always fits AES-256.
        $this->key = hash('sha256', $appSecret, true);
    }

    /**
     * Encrypt a plaintext string value.
     *
     * Returns a structured envelope array that can be JSON-encoded:
     * {
     *   "__encrypted": true,
     *   "cipher":      "AES-256-GCM",
     *   "iv":          "<base64>",
     *   "tag":         "<base64>",
     *   "ciphertext":  "<base64>"
     * }
     *
     * @param string $plaintext Value to encrypt.
     * @return array<string, mixed> Envelope array.
     * @throws RuntimeException When encryption fails.
     */
    public function encryptValue(string $plaintext): array
    {
        $iv = random_bytes(12); // 96-bit IV recommended for GCM

        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16 // 128-bit authentication tag
        );

        if ($ciphertext === false) {
            throw new RuntimeException('BackupEncryptionService: openssl_encrypt failed — ' . openssl_error_string());
        }

        return [
            self::MARKER => true,
            'cipher'     => self::CIPHER,
            'iv'         => base64_encode($iv),
            'tag'        => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        ];
    }

    /**
     * Decrypt an envelope produced by {@see encryptValue()}.
     *
     * @param array<string, mixed> $envelope The encrypted envelope.
     * @return string The original plaintext value.
     * @throws RuntimeException When decryption fails (e.g. wrong APP_SECRET or corrupted data).
     */
    public function decryptValue(array $envelope): string
    {
        $iv         = base64_decode((string) ($envelope['iv'] ?? ''), true);
        $tag        = base64_decode((string) ($envelope['tag'] ?? ''), true);
        $ciphertext = base64_decode((string) ($envelope['ciphertext'] ?? ''), true);

        if ($iv === false || $tag === false || $ciphertext === false) {
            throw new RuntimeException(
                'Encrypted secret could not be decrypted — ensure APP_SECRET matches the source environment, '
                . 'or replace the secret manually after restore'
            );
        }

        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            throw new RuntimeException(
                'Encrypted secret could not be decrypted — ensure APP_SECRET matches the source environment, '
                . 'or replace the secret manually after restore'
            );
        }

        return $plaintext;
    }

    /**
     * Return true when the given value is an encrypted envelope produced by this service.
     *
     * @param mixed $value Any value from a backup's data section.
     */
    public function isEncrypted(mixed $value): bool
    {
        return is_array($value) && isset($value[self::MARKER]) && $value[self::MARKER] === true;
    }

    /**
     * Return true when the given setting key should have its value encrypted in backup exports.
     *
     * The match is case-insensitive substring search against a fixed list of sensitive patterns.
     */
    public function isSensitiveKey(string $key): bool
    {
        $lower = strtolower($key);
        foreach (['secret', 'password', 'private_key', 'api_key', 'client_secret', 'smtp_pass', 'oauth'] as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }
        return false;
    }
}
