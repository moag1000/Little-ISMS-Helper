<?php

declare(strict_types=1);

namespace App\Service\Sso;

use RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * AEAD encryption for IdP client secrets.
 *
 * Uses libsodium XChaCha20-Poly1305 with a 32-byte key derived from
 * %kernel.secret% via BLAKE2b. Ciphertext format: base64(nonce || cipher).
 *
 * The encryption key MUST remain stable across deployments — rotating
 * %kernel.secret% would render existing secrets unreadable.
 */
final class SsoSecretEncryption
{
    private const CTX = 'lismsh-sso-v1';

    private string $key;

    public function __construct(#[\SensitiveParameter] #[Autowire('%kernel.secret%')] string $kernelSecret)
    {
        if (!function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
            throw new RuntimeException('libsodium extension is required for SSO secret encryption.');
        }
        if ($kernelSecret === '') {
            throw new RuntimeException('Kernel secret must not be empty.');
        }
        $this->key = sodium_crypto_generichash($kernelSecret, self::CTX, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    }

    public function encrypt(#[\SensitiveParameter] ?string $plaintext): ?string
    {
        if ($plaintext === null || $plaintext === '') {
            return null;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::CTX, $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(?string $envelope): ?string
    {
        if ($envelope === null || $envelope === '') {
            return null;
        }
        $raw = base64_decode($envelope, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new RuntimeException('Malformed SSO secret envelope.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, self::CTX, $nonce, $this->key);
        if ($plain === false) {
            throw new RuntimeException('SSO secret decryption failed (key changed or ciphertext tampered).');
        }

        return $plain;
    }
}
