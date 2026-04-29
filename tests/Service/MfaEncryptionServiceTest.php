<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MfaEncryptionService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MfaEncryptionServiceTest extends TestCase
{
    private MfaEncryptionService $service;

    protected function setUp(): void
    {
        $this->service = new MfaEncryptionService('test-app-secret-for-unit-tests');
    }

    #[Test]
    public function encryptReturnsEncPrefixedString(): void
    {
        $encrypted = $this->service->encrypt('JBSWY3DPEHPK3PXP');
        $this->assertStringStartsWith('enc:', $encrypted);
    }

    #[Test]
    public function decryptRoundTrips(): void
    {
        $plaintext = 'JBSWY3DPEHPK3PXP';
        $encrypted = $this->service->encrypt($plaintext);
        $decrypted = $this->service->decrypt($encrypted);
        $this->assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function decryptPlaintextPassesThrough(): void
    {
        // Pre-migration plaintext secrets should pass through unchanged
        $plaintext = 'JBSWY3DPEHPK3PXP';
        $result = $this->service->decrypt($plaintext);
        $this->assertSame($plaintext, $result);
    }

    #[Test]
    public function isEncryptedDetectsPrefix(): void
    {
        $this->assertFalse($this->service->isEncrypted('JBSWY3DPEHPK3PXP'));
        $this->assertTrue($this->service->isEncrypted('enc:abc123'));
    }

    #[Test]
    public function encryptProducesDifferentCiphertexts(): void
    {
        $plaintext = 'JBSWY3DPEHPK3PXP';
        $a = $this->service->encrypt($plaintext);
        $b = $this->service->encrypt($plaintext);
        // Different nonces → different ciphertexts
        $this->assertNotSame($a, $b);
        // But both decrypt to same plaintext
        $this->assertSame($plaintext, $this->service->decrypt($a));
        $this->assertSame($plaintext, $this->service->decrypt($b));
    }

    #[Test]
    public function wrongKeyFailsDecryption(): void
    {
        $service1 = new MfaEncryptionService('key-one');
        $service2 = new MfaEncryptionService('key-two');

        $encrypted = $service1->encrypt('JBSWY3DPEHPK3PXP');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/decryption failed/');
        $service2->decrypt($encrypted);
    }

    #[Test]
    public function corruptedCiphertextThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->decrypt('enc:not-valid-base64!!!');
    }
}
