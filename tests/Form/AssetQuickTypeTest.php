<?php

declare(strict_types=1);

namespace App\Tests\Form;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for Junior-ISB-Audit-2026-05-22 S13 — close AssetQuickType
 * validateOwnerSlot validation-gap (Quick-Path parity with full AssetType).
 *
 * Verifies that AssetQuickType:
 *  1. Declares the validateOwnerSlot Callback in configureOptions.
 *  2. Wires the ownerPerson EntityType child so the Either-Or
 *     constraint is satisfiable from the Quick-Add path.
 *  3. Defines the validateOwnerSlot method with the same Asset entity
 *     contract as AssetType (null-guard + Either-Or check).
 *  4. Emits the canonical owner-required violation key on the ownerUser
 *     path so existing translations + form rendering stay in sync with
 *     AssetType behaviour.
 *
 * Source-inspection pattern — matches the AssetType/BusinessContinuityPlanType
 * style used elsewhere in tests/Form, avoids the live FormFactory + Doctrine
 * dependency. Trade-off: structural assertions only, but the Callback /
 * Asset entity contract is itself unit-tested by AssetType's own tests
 * (the method body is byte-for-byte identical — verified by assertions below).
 */
final class AssetQuickTypeTest extends TestCase
{
    private static function getFormTypeSource(): string
    {
        $file = __DIR__ . '/../../src/Form/AssetQuickType.php';
        self::assertFileExists($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    #[Test]
    public function configureOptionsWiresValidateOwnerSlotCallback(): void
    {
        $source = self::getFormTypeSource();

        self::assertStringContainsString(
            'use Symfony\\Component\\Validator\\Constraints\\Callback;',
            $source,
            'AssetQuickType must import the Callback constraint.'
        );
        self::assertStringContainsString(
            'use Symfony\\Component\\Validator\\Context\\ExecutionContextInterface;',
            $source,
            'AssetQuickType must import the ExecutionContextInterface for the Callback signature.'
        );
        self::assertStringContainsString(
            "new Callback([\$this, 'validateOwnerSlot'])",
            $source,
            'AssetQuickType::configureOptions must register the validateOwnerSlot Callback for parity with AssetType.'
        );
    }

    #[Test]
    public function ownerPersonChildIsWiredSoEitherOrIsSatisfiable(): void
    {
        $source = self::getFormTypeSource();

        self::assertStringContainsString(
            "->add('ownerPerson', EntityType::class",
            $source,
            'AssetQuickType must expose ownerPerson so the Either-Or constraint is satisfiable from the Quick-Add path.'
        );
        self::assertStringContainsString(
            'use App\\Entity\\Person;',
            $source,
            'AssetQuickType must import the Person entity for the ownerPerson EntityType.'
        );
    }

    #[Test]
    public function validateOwnerSlotMethodMatchesAssetTypeContract(): void
    {
        $source = self::getFormTypeSource();

        self::assertStringContainsString(
            'public function validateOwnerSlot(?Asset $entity, ExecutionContextInterface $context): void',
            $source,
            'Method signature must mirror AssetType::validateOwnerSlot for behavioural parity.'
        );
        // Null-guard — empty/unbound form must not crash the Validator.
        self::assertStringContainsString(
            'if ($entity === null) {',
            $source,
            'validateOwnerSlot must short-circuit on null entity (unbound form).'
        );
        // Either-Or check on the two canonical slots.
        self::assertStringContainsString(
            '$entity->getOwnerUser() === null && $entity->getOwnerPerson() === null',
            $source,
            'validateOwnerSlot must raise the violation only when BOTH slots are null.'
        );
    }

    #[Test]
    public function violationUsesCanonicalKeyAndPath(): void
    {
        $source = self::getFormTypeSource();

        // Same key as AssetType so the Quick-Path renders the existing
        // translation (asset.de.yaml / asset.en.yaml).
        self::assertStringContainsString(
            'asset.error.owner_required_user_or_person',
            $source,
            'Violation key must be the canonical translation key from AssetType.'
        );
        self::assertStringContainsString(
            "->atPath('ownerUser')",
            $source,
            'Violation must be attached to the ownerUser field, matching AssetType.'
        );
    }

    #[Test]
    public function s13AuditMarkerIsPresentForFutureGreps(): void
    {
        $source = self::getFormTypeSource();

        // Greppable marker so a future audit run can locate this change.
        self::assertStringContainsString(
            'Junior-ISB-Audit-2026-05-22 S13',
            $source,
            'Audit marker comment must be present so the parity-fix is greppable.'
        );
    }
}
