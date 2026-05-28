<?php

declare(strict_types=1);

namespace App\Tests\Service\Restore;

use App\Entity\User;
use App\Service\BackupEncryptionService;
use App\Service\Restore\RestoreSecretsHandler;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AllowMockObjectsWithoutExpectations]
final class RestoreSecretsHandlerTest extends TestCase
{
    private MockObject $entityManager;
    private MockObject $logger;
    private MockObject $passwordHasher;
    private BackupEncryptionService $encryption;
    private RestoreSecretsHandler $handler;

    protected function setUp(): void
    {
        $this->entityManager  = $this->createMock(EntityManagerInterface::class);
        $this->logger         = $this->createMock(LoggerInterface::class);
        $this->passwordHasher = $this->createMock(UserPasswordHasherInterface::class);
        $this->encryption     = new BackupEncryptionService('test_secret_for_handler_tests');

        $this->handler = new RestoreSecretsHandler(
            $this->entityManager,
            $this->logger,
            $this->passwordHasher,
            $this->encryption,
        );
    }

    // ────────────────────────────────────────────────────────────────────────
    // markSystemSettingsForDecryption
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function mark_settings_flags_encrypted_rows(): void
    {
        $encryptedValue = $this->encryption->encryptValue('secret-value');
        $rows = [
            ['key' => 'plain_key', 'value' => 'plain-text'],
            ['key' => 'enc_key',   'value' => $encryptedValue],
        ];

        $result = $this->handler->markSystemSettingsForDecryption($rows);

        self::assertArrayNotHasKey('__needs_decrypt__', $result[0]);
        self::assertTrue($result[1]['__needs_decrypt__'] ?? false);
    }

    #[Test]
    public function mark_settings_returns_unchanged_when_no_encryption_service(): void
    {
        $handlerNoEncryption = new RestoreSecretsHandler(
            $this->entityManager,
            $this->logger,
            $this->passwordHasher,
            null,
        );

        $rows = [['key' => 'k', 'value' => 'v']];
        $result = $handlerNoEncryption->markSystemSettingsForDecryption($rows);

        self::assertSame($rows, $result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // decryptSystemSettingRow
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function decrypt_row_decrypts_marked_row(): void
    {
        $plain = 'my-secret-value';
        $encryptedValue = $this->encryption->encryptValue($plain);

        $row = ['key' => 'enc_key', 'value' => $encryptedValue, '__needs_decrypt__' => true];
        $result = $this->handler->decryptSystemSettingRow($row);

        self::assertSame($plain, $result['value']);
        self::assertArrayNotHasKey('__needs_decrypt__', $result);
    }

    #[Test]
    public function decrypt_row_removes_flag_when_no_encryption_service(): void
    {
        $handlerNoEncryption = new RestoreSecretsHandler(
            $this->entityManager,
            $this->logger,
            $this->passwordHasher,
            null,
        );

        $row = ['key' => 'k', 'value' => 'v', '__needs_decrypt__' => true];
        $result = $handlerNoEncryption->decryptSystemSettingRow($row);

        self::assertSame('v', $result['value']);
        self::assertArrayNotHasKey('__needs_decrypt__', $result);
    }

    // ────────────────────────────────────────────────────────────────────────
    // decryptSystemSettingsValues (eager/batch)
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function decrypt_settings_values_decrypts_all_encrypted_rows(): void
    {
        $plain1 = 'secret-a';
        $plain2 = 'secret-b';
        $rows = [
            ['key' => 'k1', 'value' => $this->encryption->encryptValue($plain1)],
            ['key' => 'k2', 'value' => 'plain-text'],
            ['key' => 'k3', 'value' => $this->encryption->encryptValue($plain2)],
        ];

        $result = $this->handler->decryptSystemSettingsValues($rows);

        self::assertSame($plain1,      $result[0]['value']);
        self::assertSame('plain-text', $result[1]['value']);
        self::assertSame($plain2,      $result[2]['value']);
    }

    // ────────────────────────────────────────────────────────────────────────
    // applyAdminPasswordToUser
    // ────────────────────────────────────────────────────────────────────────

    #[Test]
    public function apply_admin_password_hashes_and_sets_password_on_user(): void
    {
        $user = new User();
        $data = ['email' => 'admin@example.com', 'id' => 1];
        $warnings = [];

        $this->passwordHasher
            ->method('hashPassword')
            ->with($user, 'admin123')
            ->willReturn('$hashed$admin123');

        $this->handler->applyAdminPasswordToUser($user, $data, 'admin123', $warnings);

        // Warnings should contain a message about the restored user
        self::assertNotEmpty($warnings);
        self::assertStringContainsString('admin@example.com', $warnings[0]);
    }

    #[Test]
    public function apply_admin_password_adds_warning_on_hasher_exception(): void
    {
        $user = new User();
        $data = ['email' => 'admin@example.com', 'id' => 1];
        $warnings = [];

        $this->passwordHasher
            ->method('hashPassword')
            ->willThrowException(new \RuntimeException('Hasher exploded'));

        $this->handler->applyAdminPasswordToUser($user, $data, 'pass', $warnings);

        self::assertNotEmpty($warnings);
        self::assertStringContainsString('WARNING', $warnings[0]);
    }
}
