<?php

declare(strict_types=1);

namespace App\Service\Sso;

/**
 * Contract for AEAD secret encryption services.
 * Exists to allow mocking in WebhookChannel tests
 * without requiring SsoSecretEncryption (which uses libsodium) to be non-final.
 */
interface SecretEncryptionInterface
{
    public function encrypt(?string $plaintext): ?string;

    public function decrypt(?string $envelope): ?string;
}
