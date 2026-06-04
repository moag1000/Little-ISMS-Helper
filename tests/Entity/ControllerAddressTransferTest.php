<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * W3b structural coverage (M-5 + M-7). Source-inspection pattern — robust under
 * the shared-vendor multi-worktree setup where the autoloader baseDir may
 * resolve to a sibling worktree and shadow local entity changes (mirrors
 * ProcessingActivityValidationTest).
 */
final class ControllerAddressTransferTest extends TestCase
{
    private static function source(string $relative): string
    {
        $file = __DIR__ . '/../../src/' . $relative;
        self::assertFileExists($file);
        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    // ── M-5 — Tenant postal address (Art. 30(1)(a)) + representative (Art. 27)

    #[Test]
    public function tenantDeclaresPostalAddressFields(): void
    {
        $source = self::source('Entity/Tenant.php');

        foreach (['addressStreet', 'addressPostalCode', 'addressCity', 'addressCountry'] as $field) {
            self::assertStringContainsString('private ?string $' . $field . ' = null;', $source, "Tenant must declare {$field}");
            self::assertStringContainsString('function get' . ucfirst($field) . '(', $source);
            self::assertStringContainsString('function set' . ucfirst($field) . '(', $source);
        }
    }

    #[Test]
    public function tenantDeclaresArt27RepresentativeFields(): void
    {
        $source = self::source('Entity/Tenant.php');

        self::assertStringContainsString('private ?string $representativeName = null;', $source);
        self::assertStringContainsString('private ?string $representativeContact = null;', $source);
        self::assertStringContainsString('function getRepresentativeName(', $source);
        self::assertStringContainsString('function getRepresentativeContact(', $source);
    }

    // ── M-7 — Supplier third-country transfer (Art. 44–49) ───────────────

    #[Test]
    public function supplierDeclaresThirdCountryTransferFields(): void
    {
        $source = self::source('Entity/Supplier.php');

        self::assertStringContainsString('private bool $thirdCountryTransfer = false;', $source);
        self::assertStringContainsString('private ?string $transferSafeguards = null;', $source);
        self::assertStringContainsString('function hasThirdCountryTransfer(): bool', $source);
        self::assertStringContainsString('function getTransferSafeguards(): ?string', $source);
    }

    #[Test]
    public function formsExposeTheNewFields(): void
    {
        $tenantForm = self::source('Form/Admin/TenantComplianceSettingsType.php');
        self::assertStringContainsString("->add('addressStreet'", $tenantForm);
        self::assertStringContainsString("->add('representativeName'", $tenantForm);

        $supplierForm = self::source('Form/SupplierType.php');
        self::assertStringContainsString("->add('thirdCountryTransfer'", $supplierForm);
        self::assertStringContainsString("->add('transferSafeguards'", $supplierForm);
    }
}
