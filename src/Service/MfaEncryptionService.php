<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

/**
 * Application-level encryption for MFA TOTP secrets.
 *
 * Uses libsodium XSalsa20-Poly1305 (crypto_secretbox) with a dedicated
 * encryption key (MFA_ENCRYPTION_KEY env var), falling back to APP_SECRET
 * derivation if not set. Encrypted values are prefixed with "enc:" so
 * plaintext secrets (pre-migration) can be detected transparently.
 *
 * Key rotation: set MFA_ENCRYPTION_KEY, run app:encrypt-mfa-secrets to
 * re-encrypt all secrets under the new key.
 *
 * PenTest Finding PT-003 (CVSS 6.5): TOTP secrets were stored in cleartext.
 * Backup codes were already Argon2id-hashed — this closes the gap.
 */
class MfaEncryptionService
{
    private const string ENCRYPTED_PREFIX = 'enc:';

    private string $encryptionKey;

    public function __construct(
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%kernel.secret%')]
        string $appSecret,
        #[\Symfony\Component\DependencyInjection\Attribute\Autowire('%env(default::MFA_ENCRYPTION_KEY)%')]
        ?string $mfaEncryptionKey = null,
    ) {
        // Prefer dedicated MFA_ENCRYPTION_KEY env var; fall back to APP_SECRET
        $source = ($mfaEncryptionKey !== null && $mfaEncryptionKey !== '')
            ? $mfaEncryptionKey
            : $appSecret;

        $this->encryptionKey = sodium_crypto_generichash(
            $source,
            '',
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES
        );
    }

    public function __destruct()
    {
        // Defense-in-depth: wipe key from memory when service is destroyed.
        // PHP 8.5 disallows indirect modify of typed-properties via reference
        // (sodium_memzero takes by-ref). Detour via a local copy + reassign.
        if ($this->encryptionKey !== '') {
            $key = $this->encryptionKey;
            sodium_memzero($key);
            $this->encryptionKey = '';
        }
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
