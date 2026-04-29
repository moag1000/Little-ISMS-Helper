<?php

declare(strict_types=1);

namespace App\Tests\Service\Sso;

use App\Service\Sso\SsoSecretEncryption;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SsoSecretEncryptionTest extends TestCase
{
    #[Test]
    public function encryptDecryptRoundTripWorks(): void
    {
        $svc = new SsoSecretEncryption('test-kernel-secret');
        $plain = 'super-secret-client-value';
        $cipher = $svc->encrypt($plain);

        self::assertNotNull($cipher);
        self::assertNotSame($plain, $cipher);
        self::assertSame($plain, $svc->decrypt($cipher));
    }

    #[Test]
    public function encryptionIsNonDeterministic(): void
    {
        $svc = new SsoSecretEncryption('test-kernel-secret');
        $a = $svc->encrypt('value');
        $b = $svc->encrypt('value');
        self::assertNotSame($a, $b, 'XChaCha20 must use a fresh nonce per call');
    }

    #[Test]
    public function decryptWithDifferentKeyFails(): void
    {
        $svcA = new SsoSecretEncryption('secret-a');
        $svcB = new SsoSecretEncryption('secret-b');
        $cipher = $svcA->encrypt('value');

        $this->expectException(RuntimeException::class);
        $svcB->decrypt($cipher);
    }

    #[Test]
    public function decryptHandlesNullAndEmpty(): void
    {
        $svc = new SsoSecretEncryption('test-kernel-secret');
        self::assertNull($svc->decrypt(null));
        self::assertNull($svc->decrypt(''));
        self::assertNull($svc->encrypt(null));
        self::assertNull($svc->encrypt(''));
    }

    #[Test]
    public function emptyKernelSecretIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        new SsoSecretEncryption('');
    }
}
