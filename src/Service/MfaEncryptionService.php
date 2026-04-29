<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * Application-level encryption for MFA TOTP secrets.
 *
 * Uses libsodium XSalsa20-Poly1305 (crypto_secretbox) with a key derived
 * from APP_SECRET. Encrypted values are prefixed with "enc:" so plaintext
 * secrets (pre-migration) can be detected and handled transparently.
 *
 * PenTest Finding PT-003 (CVSS 6.5): TOTP secrets were stored in cleartext.
 * Backup codes were already Argon2id-hashed — this closes the gap.
 */
class MfaEncryptionService
{
    private const string ENCRYPTED_PREFIX = 'enc:';

    private readonly string $encryptionKey;

    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.secret%')]
        string $appSecret,
    ) {
        // Derive a fixed 32-byte key from APP_SECRET using BLAKE2b
        $this->encryptionKey = sodium_crypto_generichash(
            $appSecret,
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }

    /**
     * Encrypt a plaintext TOTP secret.
     *
     * @return string Base64-encoded nonce+ciphertext, prefixed with "enc:"
     */
    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->encryptionKey);

        return self::ENCRYPTED_PREFIX . base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt an encrypted TOTP secret.
     *
     * If the value is not encrypted (no "enc:" prefix), returns it as-is
     * for backward compatibility during migration.
     */
    public function decrypt(string $value): string
    {
        if (!$this->isEncrypted($value)) {
            return $value;
        }

        $encoded = substr($value, strlen(self::ENCRYPTED_PREFIX));
        $decoded = base64_decode($encoded, true);

        if ($decoded === false || strlen($decoded) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new RuntimeException('Invalid encrypted MFA secret: decoding failed');
        }

        $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->encryptionKey);

        if ($plaintext === false) {
            throw new RuntimeException('MFA secret decryption failed — APP_SECRET may have changed');
        }

        return $plaintext;
    }

    /**
     * Check whether a stored value is already encrypted.
     */
    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::ENCRYPTED_PREFIX);
    }
}
