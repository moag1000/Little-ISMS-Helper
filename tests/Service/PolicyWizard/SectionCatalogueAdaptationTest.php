<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\DocumentSection;
use App\Service\PolicyWizard\DoraExtensionCatalogue;
use App\Service\PolicyWizard\GdprSectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtension;
use App\Service\PolicyWizard\SectionExtension\StandardSectionCatalogueInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that both catalogue classes correctly implement
 * {@see StandardSectionCatalogueInterface} and produce well-formed
 * {@see SectionExtension} DTOs via sectionsForTopic().
 */
final class SectionCatalogueAdaptationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Interface contract
    // -------------------------------------------------------------------------

    #[Test]
    public function gdprCatalogueImplementsInterface(): void
    {
        self::assertInstanceOf(StandardSectionCatalogueInterface::class, new GdprSectionCatalogue());
    }

    #[Test]
    public function doraCatalogueImplementsInterface(): void
    {
        self::assertInstanceOf(StandardSectionCatalogueInterface::class, new DoraExtensionCatalogue());
    }

    // -------------------------------------------------------------------------
    // getStandard()
    // -------------------------------------------------------------------------

    #[Test]
    public function gdprCatalogueReturnsGdprToken(): void
    {
        self::assertSame('gdpr', (new GdprSectionCatalogue())->getStandard());
    }

    #[Test]
    public function doraCatalogueReturnsDoraToken(): void
    {
        self::assertSame('dora', (new DoraExtensionCatalogue())->getStandard());
    }

    // -------------------------------------------------------------------------
    // GdprSectionCatalogue::sectionsForTopic()
    // -------------------------------------------------------------------------

    #[Test]
    public function gdprSectionsForTopicReturnsCorrectDtoForAcceptableUse(): void
    {
        $extensions = (new GdprSectionCatalogue())->sectionsForTopic('acceptable_use');

        self::assertCount(1, $extensions);
        $ext = $extensions[0];
        self::assertInstanceOf(SectionExtension::class, $ext);
        self::assertSame('gdpr_lawful_basis_workplace', $ext->sectionKey);
        self::assertSame('gdpr', $ext->standard);
        self::assertSame(['Art. 6', 'Art. 9'], $ext->controlRefs);
        self::assertSame(DocumentSection::APPROVAL_ROLE_DPO, $ext->approvalRole);
        self::assertSame('policy_wizard.gdpr_section.gdpr_lawful_basis_workplace.body', $ext->bodyTranslationKey);
        self::assertSame('document_section', $ext->renderMode);
    }

    #[Test]
    public function gdprSectionsForTopicReturnsCorrectDtoForIncidentManagement(): void
    {
        $extensions = (new GdprSectionCatalogue())->sectionsForTopic('incident_management');

        self::assertCount(1, $extensions);
        $ext = $extensions[0];
        self::assertSame('gdpr_breach_72h', $ext->sectionKey);
        self::assertSame('gdpr', $ext->standard);
        self::assertSame(['Art. 33'], $ext->controlRefs);
        self::assertSame(DocumentSection::APPROVAL_ROLE_DPO, $ext->approvalRole);
        self::assertSame('policy_wizard.gdpr_section.gdpr_breach_72h.body', $ext->bodyTranslationKey);
        self::assertSame('document_section', $ext->renderMode);
    }

    #[Test]
    public function gdprSectionsForTopicReturnsTwoDtosForSecureDevelopment(): void
    {
        $extensions = (new GdprSectionCatalogue())->sectionsForTopic('secure_development');

        self::assertCount(2, $extensions);

        // First: gdpr_privacy_by_design (joint approval)
        self::assertSame('gdpr_privacy_by_design', $extensions[0]->sectionKey);
        self::assertSame('gdpr', $extensions[0]->standard);
        self::assertSame(['Art. 25'], $extensions[0]->controlRefs);
        self::assertSame(DocumentSection::APPROVAL_ROLE_JOINT, $extensions[0]->approvalRole);
        self::assertSame('document_section', $extensions[0]->renderMode);

        // Second: gdpr_ai_systems (dpo approval)
        self::assertSame('gdpr_ai_systems', $extensions[1]->sectionKey);
        self::assertSame('gdpr', $extensions[1]->standard);
        self::assertSame(['Art. 22', 'EU AI Act'], $extensions[1]->controlRefs);
        self::assertSame(DocumentSection::APPROVAL_ROLE_DPO, $extensions[1]->approvalRole);
        self::assertSame('document_section', $extensions[1]->renderMode);
    }

    #[Test]
    public function gdprSectionsForTopicReturnsEmptyListForUnknownTopic(): void
    {
        self::assertSame([], (new GdprSectionCatalogue())->sectionsForTopic('backup'));
        self::assertSame([], (new GdprSectionCatalogue())->sectionsForTopic('compliance_review'));
        self::assertSame([], (new GdprSectionCatalogue())->sectionsForTopic(''));
    }

    // -------------------------------------------------------------------------
    // DoraExtensionCatalogue::sectionsForTopic()
    // -------------------------------------------------------------------------

    #[Test]
    public function doraSectionsForTopicReturnsCorrectDtoForBackup(): void
    {
        $extensions = (new DoraExtensionCatalogue())->sectionsForTopic('backup');

        self::assertCount(1, $extensions);
        $ext = $extensions[0];
        self::assertInstanceOf(SectionExtension::class, $ext);
        self::assertSame('dora_extension', $ext->sectionKey);
        self::assertSame('dora', $ext->standard);
        self::assertSame(['Art. 12'], $ext->controlRefs);
        self::assertSame('ciso', $ext->approvalRole);
        self::assertSame('policy.iso27001.backup.v1.dora_extension.body', $ext->bodyTranslationKey);
        self::assertSame('body_extension', $ext->renderMode);
    }

    #[Test]
    public function doraSectionsForTopicReturnsCorrectDtoForIncidentManagement(): void
    {
        $extensions = (new DoraExtensionCatalogue())->sectionsForTopic('incident_management');

        self::assertCount(1, $extensions);
        $ext = $extensions[0];
        self::assertSame('dora_extension', $ext->sectionKey);
        self::assertSame('dora', $ext->standard);
        self::assertCount(7, $ext->controlRefs);
        self::assertSame('Art. 17', $ext->controlRefs[0]);
        self::assertSame('Art. 23', $ext->controlRefs[6]);
        self::assertSame('ciso', $ext->approvalRole);
        self::assertSame('policy.iso27001.incident_management.v1.dora_extension.body', $ext->bodyTranslationKey);
        self::assertSame('body_extension', $ext->renderMode);
    }

    #[Test]
    public function doraSectionsForTopicReturnsEmptyListForTopicWithNoExtension(): void
    {
        self::assertSame([], (new DoraExtensionCatalogue())->sectionsForTopic('compliance_review'));
        self::assertSame([], (new DoraExtensionCatalogue())->sectionsForTopic('internal_audit_programme'));
        self::assertSame([], (new DoraExtensionCatalogue())->sectionsForTopic('risk_appetite'));
        self::assertSame([], (new DoraExtensionCatalogue())->sectionsForTopic(''));
    }
}
