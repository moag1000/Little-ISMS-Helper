<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\BackupEncryptionService;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use PHPUnit\Framework\Attributes\Test;

class BackupEncryptionServiceTest extends TestCase
{
    private BackupEncryptionService $service;
    private string $appSecret = 'test_app_secret_for_unit_tests';

    protected function setUp(): void
    {
        $this->service = new BackupEncryptionService($this->appSecret);
    }

    // ------------------------------------------------------------------ //
    // Round-trip                                                          //
    // ------------------------------------------------------------------ //

    #[Test]
    public function testEncryptDecryptRoundTrip(): void
    {
        $plaintext = 'super-secret-password-123!';
        $envelope  = $this->service->encryptValue($plaintext);
        $decrypted = $this->service->decryptValue($envelope);

        $this->assertSame($plaintext, $decrypted);
    }

    #[Test]
    public function testEncryptProducesExpectedEnvelopeKeys(): void
    {
        $envelope = $this->service->encryptValue('value');

        $this->assertArrayHasKey('__encrypted', $envelope);
        $this->assertTrue($envelope['__encrypted']);
        $this->assertArrayHasKey('cipher', $envelope);
        $this->assertSame('aes-256-gcm', $envelope['cipher']);
        $this->assertArrayHasKey('iv', $envelope);
        $this->assertArrayHasKey('tag', $envelope);
        $this->assertArrayHasKey('ciphertext', $envelope);
    }

    #[Test]
    public function testEachEncryptCallProducesDifferentIv(): void
    {
        $a = $this->service->encryptValue('same');
        $b = $this->service->encryptValue('same');

        // IVs must differ (random nonce per call)
        $this->assertNotSame($a['iv'], $b['iv']);
    }

    #[Test]
    public function testRoundTripPreservesEmptyString(): void
    {
        $envelope  = $this->service->encryptValue('');
        $decrypted = $this->service->decryptValue($envelope);
        $this->assertSame('', $decrypted);
    }

    #[Test]
    public function testRoundTripPreservesUnicodeValue(): void
    {
        $plaintext = 'Ünïcödé sécret — パスワード';
        $decrypted = $this->service->decryptValue($this->service->encryptValue($plaintext));
        $this->assertSame($plaintext, $decrypted);
    }

    // ------------------------------------------------------------------ //
    // Wrong-key scenario                                                  //
    // ------------------------------------------------------------------ //

    #[Test]
    public function testDecryptWithWrongKeyThrowsRuntimeException(): void
    {
        $envelope = $this->service->encryptValue('my-api-key-abc123');

        // A service initialised with a different APP_SECRET must fail to decrypt.
        $wrongService = new BackupEncryptionService('completely-different-secret');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/ensure APP_SECRET matches/');
        $wrongService->decryptValue($envelope);
    }

    #[Test]
    public function testDecryptWithCorruptedCiphertextThrows(): void
    {
        $envelope               = $this->service->encryptValue('sensitive');
        $envelope['ciphertext'] = base64_encode('CORRUPTED_GARBAGE_DATA_THAT_WONT_DECRYPT');

        $this->expectException(RuntimeException::class);
        $wrongService = new BackupEncryptionService($this->appSecret);
        $wrongService->decryptValue($envelope);
    }

    #[Test]
    public function testDecryptWithInvalidBase64Throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->service->decryptValue([
            '__encrypted' => true,
            'cipher'      => 'aes-256-gcm',
            'iv'          => '!!!not-valid-base64!!!',
            'tag'         => base64_encode(str_repeat("\x00", 16)),
            'ciphertext'  => base64_encode('data'),
        ]);
    }

    // ------------------------------------------------------------------ //
    // isEncrypted                                                         //
    // ------------------------------------------------------------------ //

    #[Test]
    public function testIsEncryptedReturnsTrueForEnvelope(): void
    {
        $envelope = $this->service->encryptValue('value');
        $this->assertTrue($this->service->isEncrypted($envelope));
    }

    #[Test]
    public function testIsEncryptedReturnsFalseForPlainString(): void
    {
        $this->assertFalse($this->service->isEncrypted('plain-string'));
    }

    #[Test]
    public function testIsEncryptedReturnsFalseForNull(): void
    {
        $this->assertFalse($this->service->isEncrypted(null));
    }

    #[Test]
    public function testIsEncryptedReturnsFalseForInteger(): void
    {
        $this->assertFalse($this->service->isEncrypted(42));
    }

    #[Test]
    public function testIsEncryptedReturnsFalseForArrayWithoutMarker(): void
    {
        $this->assertFalse($this->service->isEncrypted(['cipher' => 'aes-256-gcm']));
    }

    #[Test]
    public function testIsEncryptedReturnsFalseWhenMarkerIsFalse(): void
    {
        $this->assertFalse($this->service->isEncrypted(['__encrypted' => false, 'ciphertext' => 'x']));
    }

    // ------------------------------------------------------------------ //
    // isSensitiveKey                                                      //
    // ------------------------------------------------------------------ //

    #[\PHPUnit\Framework\Attributes\DataProvider('sensitiveKeyProvider')]
    #[Test]
    public function testIsSensitiveKeyReturnsTrueForKnownPatterns(string $key): void
    {
        $this->assertTrue($this->service->isSensitiveKey($key), "Expected $key to be sensitive");
    }

    public static function sensitiveKeyProvider(): array
    {
        return [
            ['smtp_password'],
            ['SMTP_PASSWORD'],
            ['oauth_token'],
            ['api_key'],
            ['API_KEY'],
            ['client_secret'],
            ['private_key'],
            ['smtp_pass'],
            ['app_secret'],
            ['password'],
            ['my_password_field'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonSensitiveKeyProvider')]
    #[Test]
    public function testIsSensitiveKeyReturnsFalseForNonSensitiveKeys(string $key): void
    {
        $this->assertFalse($this->service->isSensitiveKey($key), "Expected $key to be non-sensitive");
    }

    public static function nonSensitiveKeyProvider(): array
    {
        return [
            ['smtp_host'],
            ['smtp_port'],
            ['default_locale'],
            ['theme'],
            ['max_upload_size'],
            ['app_name'],
        ];
    }
}
