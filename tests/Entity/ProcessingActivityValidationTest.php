<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Junior-ISB-Audit-2026-05-22 C2-02 / C2-03 — structural coverage for the
 * VVT-dedup audit fixes on ProcessingActivity.
 *
 * Source-inspection pattern (mirrors ProcessingActivityTypeTest) — robust
 * against shared-vendor multi-worktree setups where the autoloader baseDir
 * may resolve to a sibling worktree and shadow local entity changes. The
 * runtime semantics of `validateTomOrControlsPresent` are exercised once
 * the change lands on main; on this branch CI verifies the structural
 * contract: imports + Callback attribute + violation key + property path
 * + 50-char threshold + audit markers on the affected fields.
 */
final class ProcessingActivityValidationTest extends TestCase
{
    private static function getEntitySource(): string
    {
        $file = __DIR__ . '/../../src/Entity/ProcessingActivity.php';
        self::assertFileExists($file);

        $source = file_get_contents($file);
        self::assertIsString($source);

        return $source;
    }

    // ── C2-03 — Cross-Field Validator ───────────────────────────────────

    #[Test]
    public function entityImportsExecutionContextInterface(): void
    {
        $source = self::getEntitySource();

        self::assertStringContainsString(
            'use Symfony\Component\Validator\Context\ExecutionContextInterface;',
            $source,
            'ProcessingActivity must import ExecutionContextInterface for the Callback validator (C2-03).'
        );
    }

    #[Test]
    public function entityDeclaresAssertCallbackOnValidateTomOrControlsPresent(): void
    {
        $source = self::getEntitySource();

        self::assertMatchesRegularExpression(
            "/#\[Assert\\\\Callback\]\s*\n\s*public\s+function\s+validateTomOrControlsPresent\s*\(/",
            $source,
            'ProcessingActivity must declare #[Assert\\Callback] directly above validateTomOrControlsPresent() (C2-03).'
        );
    }

    #[Test]
    public function callbackUsesCanonicalTranslationKey(): void
    {
        $source = self::getEntitySource();

        self::assertStringContainsString(
            "'processing_activity.validation.tom_or_controls_required'",
            $source,
            'Callback violation must use the canonical translation key (C2-03).'
        );
    }

    #[Test]
    public function callbackTargetsTechnicalOrganizationalMeasuresPropertyPath(): void
    {
        $source = self::getEntitySource();

        // The inline error renders best next to the TOMs textarea — the property
        // path anchors the violation on that field even though the rule also
        // considers implementedControls.
        self::assertStringContainsString(
            "->atPath('technicalOrganizationalMeasures')",
            $source,
            'Callback must target the technicalOrganizationalMeasures property path (C2-03).'
        );
    }

    #[Test]
    public function callbackEnforces50CharTomThreshold(): void
    {
        $source = self::getEntitySource();

        self::assertStringContainsString(
            '$tomLength < 50',
            $source,
            'Callback must use the documented 50-char TOM threshold from the audit TODO (C2-03).'
        );
    }

    #[Test]
    public function callbackEnforcesAtLeastOneImplementedControl(): void
    {
        $source = self::getEntitySource();

        self::assertStringContainsString(
            '$controlsCount < 1',
            $source,
            'Callback must enforce implementedControls.count >= 1 as the second evidence form (C2-03).'
        );
    }

    // ── M-6 — Art. 10 criminal-data legal-basis gate ─────────────────────

    #[Test]
    public function entityDeclaresCriminalDataLegalBasisField(): void
    {
        $source = self::getEntitySource();

        self::assertStringContainsString('private ?string $criminalDataLegalBasis = null;', $source);
        self::assertStringContainsString('public function getCriminalDataLegalBasis(): ?string', $source);
        self::assertStringContainsString('public function setCriminalDataLegalBasis(', $source);
    }

    #[Test]
    public function criminalDataValidatorIsCallbackGatedOnProcessesCriminalData(): void
    {
        $source = self::getEntitySource();

        self::assertMatchesRegularExpression(
            "/#\[Assert\\\\Callback\]\s*\n\s*public\s+function\s+validateCriminalDataLegalBasis\s*\(/",
            $source,
            'M-6: validateCriminalDataLegalBasis must carry #[Assert\\Callback].'
        );
        self::assertStringContainsString('$this->processesCriminalData &&', $source);
        self::assertStringContainsString("'processing_activity.validation.criminal_data_legal_basis_required'", $source);
        self::assertStringContainsString("->atPath('criminalDataLegalBasis')", $source);
    }

    // ── M-8 — Art. 30(1)(d) recipient-categories mandatory ───────────────

    #[Test]
    public function recipientCategoriesValidatorIsCallbackEnforcingPresence(): void
    {
        $source = self::getEntitySource();

        self::assertMatchesRegularExpression(
            "/#\[Assert\\\\Callback\]\s*\n\s*public\s+function\s+validateRecipientCategoriesPresent\s*\(/",
            $source,
            'M-8: validateRecipientCategoriesPresent must carry #[Assert\\Callback].'
        );
        self::assertStringContainsString("'processing_activity.validation.recipient_categories_required'", $source);
        self::assertStringContainsString("->atPath('recipientCategories')", $source);
    }

    // ── Audit markers (grep-ability when the roadmap is closed out) ───────

    #[Test]
    public function entityCarriesC2_02AuditMarker(): void
    {
        $source = self::getEntitySource();

        self::assertStringContainsString(
            'Junior-ISB-Audit-2026-05-22 C2-02',
            $source,
            'ProcessingActivity entity must carry the C2-02 audit marker on the retention field doc-blocks.'
        );
    }

    #[Test]
    public function entityCarriesC2_03AuditMarker(): void
    {
        $source = self::getEntitySource();

        self::assertStringContainsString(
            'Junior-ISB-Audit-2026-05-22 C2-03',
            $source,
            'ProcessingActivity entity must carry the C2-03 audit marker on TOM / implementedControls / validator.'
        );
    }
}
