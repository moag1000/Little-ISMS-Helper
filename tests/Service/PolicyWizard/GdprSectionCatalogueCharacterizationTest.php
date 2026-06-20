<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\DocumentSection;
use App\Service\PolicyWizard\GdprSectionCatalogue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Characterization tests — pin the exact current output of GdprSectionCatalogue
 * so any accidental regression in the production class is caught immediately.
 *
 * These tests MUST NOT change the production class; they describe it.
 */
final class GdprSectionCatalogueCharacterizationTest extends TestCase
{
    private GdprSectionCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->catalogue = new GdprSectionCatalogue();
    }

    #[Test]
    public function countIsExactlyTen(): void
    {
        self::assertSame(10, $this->catalogue->count());
    }

    #[Test]
    public function allReturnsAllTenRowsWithExpectedSectionKeys(): void
    {
        $rows = $this->catalogue->all();
        self::assertCount(10, $rows);

        $sectionKeys = array_column($rows, 'section_key');
        self::assertContains('gdpr_lawful_basis_workplace', $sectionKeys);
        self::assertContains('gdpr_dpo_mandate', $sectionKeys);
        self::assertContains('gdpr_special_categories', $sectionKeys);
        self::assertContains('gdpr_privacy_by_design', $sectionKeys);
        self::assertContains('gdpr_ai_systems', $sectionKeys);
        self::assertContains('gdpr_joint_controllers', $sectionKeys);
        self::assertContains('gdpr_international_transfers', $sectionKeys);
        self::assertContains('gdpr_retention_minimisation', $sectionKeys);
        self::assertContains('gdpr_breach_72h', $sectionKeys);
        self::assertContains('gdpr_premises_processing', $sectionKeys);
    }

    #[Test]
    public function getSectionsForAcceptableUseReturnsExactRow(): void
    {
        $rows = $this->catalogue->getSectionsFor('acceptable_use');

        self::assertCount(1, $rows);
        self::assertSame('acceptable_use', $rows[0]['iso_topic']);
        self::assertSame('gdpr_lawful_basis_workplace', $rows[0]['section_key']);
        self::assertSame(['Art. 6', 'Art. 9'], $rows[0]['gdpr_articles']);
        self::assertSame(DocumentSection::APPROVAL_ROLE_DPO, $rows[0]['approval_role']);
    }

    #[Test]
    public function getSectionsForIncidentManagementReturnsExactRow(): void
    {
        $rows = $this->catalogue->getSectionsFor('incident_management');

        self::assertCount(1, $rows);
        self::assertSame('incident_management', $rows[0]['iso_topic']);
        self::assertSame('gdpr_breach_72h', $rows[0]['section_key']);
        self::assertSame(['Art. 33'], $rows[0]['gdpr_articles']);
        self::assertSame(DocumentSection::APPROVAL_ROLE_DPO, $rows[0]['approval_role']);
    }

    #[Test]
    public function getSectionsForSecureDevelopmentReturnsTwoRows(): void
    {
        $rows = $this->catalogue->getSectionsFor('secure_development');

        self::assertCount(2, $rows);

        // Order is defined by the SECTIONS constant — privacy_by_design first, ai_systems second.
        self::assertSame('gdpr_privacy_by_design', $rows[0]['section_key']);
        self::assertSame(['Art. 25'], $rows[0]['gdpr_articles']);
        self::assertSame(DocumentSection::APPROVAL_ROLE_JOINT, $rows[0]['approval_role']);

        self::assertSame('gdpr_ai_systems', $rows[1]['section_key']);
        self::assertSame(['Art. 22', 'EU AI Act'], $rows[1]['gdpr_articles']);
        self::assertSame(DocumentSection::APPROVAL_ROLE_DPO, $rows[1]['approval_role']);
    }

    #[Test]
    public function getSectionsForUnknownTopicReturnsEmptyArray(): void
    {
        self::assertSame([], $this->catalogue->getSectionsFor('backup'));
        self::assertSame([], $this->catalogue->getSectionsFor('compliance_review'));
        self::assertSame([], $this->catalogue->getSectionsFor(''));
        self::assertSame([], $this->catalogue->getSectionsFor('___unknown___'));
    }
}
