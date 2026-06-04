<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * W4 structural coverage (N-2 § 38 DPO register, N-3 Art. 12(3) extension
 * notice). Source-inspection pattern — robust under the shared-vendor
 * multi-worktree setup (mirrors ProcessingActivityValidationTest).
 */
final class DpoRegisterExtensionNoticeTest extends TestCase
{
    private static function source(string $relative): string
    {
        $file = __DIR__ . '/../../src/' . $relative;
        self::assertFileExists($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    // ── N-2 — BDSG § 38 / Art. 37 DPO appointment register ───────────────

    #[Test]
    public function tenantDeclaresDpoRegisterFields(): void
    {
        $source = self::source('Entity/Tenant.php');

        self::assertStringContainsString('private ?\DateTimeImmutable $dpoAppointmentDate = null;', $source);
        self::assertStringContainsString('private bool $dpoIsExternal = false;', $source);
        self::assertStringContainsString('private ?\DateTimeImmutable $dpoAuthorityNotifiedAt = null;', $source);
        self::assertStringContainsString('private ?string $dpoDeputyName = null;', $source);
        self::assertStringContainsString('function getDpoAppointmentDate(', $source);
        self::assertStringContainsString('function isDpoExternal(', $source);
    }

    #[Test]
    public function tenantFormExposesDpoRegisterFields(): void
    {
        $form = self::source('Form/Admin/TenantComplianceSettingsType.php');

        self::assertStringContainsString("->add('dpoAppointmentDate'", $form);
        self::assertStringContainsString("->add('dpoAuthorityNotifiedAt'", $form);
        self::assertStringContainsString("->add('dpoDeputyName'", $form);
    }

    // ── N-3 — Art. 12(3) extension-notified proof ────────────────────────

    #[Test]
    public function dsrDeclaresExtensionNotifiedAt(): void
    {
        $source = self::source('Entity/DataSubjectRequest.php');

        self::assertStringContainsString('private ?DateTimeImmutable $extensionNotifiedAt = null;', $source);
        self::assertStringContainsString('function getExtensionNotifiedAt(): ?DateTimeImmutable', $source);
        self::assertStringContainsString('function setExtensionNotifiedAt(', $source);
    }

    #[Test]
    public function dsrFormExposesExtensionNotifiedAt(): void
    {
        $form = self::source('Form/DataSubjectRequestType.php');

        self::assertStringContainsString("->add('extensionNotifiedAt'", $form);
    }
}
