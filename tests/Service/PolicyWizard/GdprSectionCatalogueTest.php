<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\DocumentSection;
use App\Service\PolicyWizard\GdprSectionCatalogue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W6-C — GdprSectionCatalogue tests.
 *
 * Verifies the catalogue stays aligned with `06-dpo-input.md` §0
 * Decision Matrix v2 (10 sections) and offers the lookup helper
 * required by {@see \App\Service\PolicyWizard\DocumentGenerator}.
 */
final class GdprSectionCatalogueTest extends TestCase
{
    #[Test]
    public function testCatalogueHasExactlyTenEntries(): void
    {
        $catalogue = new GdprSectionCatalogue();

        self::assertSame(
            10,
            $catalogue->count(),
            'catalogue must hold exactly the 10 sections enumerated in §0 Decision Matrix v2',
        );

        // Every section_key must be unique within the catalogue —
        // duplicates would silently collide on the (document_id,
        // section_key) unique index in the database.
        $keys = array_map(
            static fn (array $row): string => $row['section_key'],
            $catalogue->all(),
        );
        self::assertCount(
            count($keys),
            array_unique($keys),
            'every section_key in the catalogue must be unique',
        );

        // Every approval_role must be one of the entity's allowed
        // values so DocumentSection::setApprovalRole() does not throw
        // at injection time.
        foreach ($catalogue->all() as $row) {
            self::assertContains(
                $row['approval_role'],
                DocumentSection::ALLOWED_APPROVAL_ROLES,
                sprintf('row %s carries invalid approval_role "%s"', $row['section_key'], $row['approval_role']),
            );
            self::assertNotEmpty(
                $row['gdpr_articles'],
                sprintf('row %s must list at least one GDPR article', $row['section_key']),
            );
        }
    }

    #[Test]
    public function testGetSectionsForReturnsMatchingRows(): void
    {
        $catalogue = new GdprSectionCatalogue();

        // Single-section topic — incident_management has only Art. 33.
        $hits = $catalogue->getSectionsFor('incident_management');
        self::assertCount(1, $hits);
        self::assertSame('gdpr_breach_72h', $hits[0]['section_key']);
        self::assertSame(DocumentSection::APPROVAL_ROLE_DPO, $hits[0]['approval_role']);
        self::assertContains('Art. 33', $hits[0]['gdpr_articles']);

        // Multi-section topic — secure_development carries TWO sections
        // (privacy_by_design + ai_systems) per §0 Decision Matrix v2.
        $secureDev = $catalogue->getSectionsFor('secure_development');
        self::assertCount(2, $secureDev, 'secure_development must yield both PbD and AI sections');
        $keys = array_map(static fn (array $r): string => $r['section_key'], $secureDev);
        self::assertContains('gdpr_privacy_by_design', $keys);
        self::assertContains('gdpr_ai_systems', $keys);

        // Approval roles per Decision Matrix: PbD=joint, AI=dpo.
        foreach ($secureDev as $row) {
            $expectedRole = match ($row['section_key']) {
                'gdpr_privacy_by_design' => DocumentSection::APPROVAL_ROLE_JOINT,
                'gdpr_ai_systems' => DocumentSection::APPROVAL_ROLE_DPO,
                default => self::fail('unexpected secure_development row: ' . $row['section_key']),
            };
            self::assertSame($expectedRole, $row['approval_role']);
        }

        // Joint-approval row — information_classification.
        $classification = $catalogue->getSectionsFor('information_classification');
        self::assertCount(1, $classification);
        self::assertSame(DocumentSection::APPROVAL_ROLE_JOINT, $classification[0]['approval_role']);

        // CISO-owned row — physical_security (Art. 32 premises).
        $physical = $catalogue->getSectionsFor('physical_security');
        self::assertCount(1, $physical);
        self::assertSame(DocumentSection::APPROVAL_ROLE_CISO, $physical[0]['approval_role']);
    }

    #[Test]
    public function testUnknownTopicReturnsEmptyArray(): void
    {
        $catalogue = new GdprSectionCatalogue();

        // Topic not present in the catalogue — e.g. compliance_review,
        // backup, network_security. None of these get a privacy section
        // per §0 Decision Matrix v2.
        self::assertSame([], $catalogue->getSectionsFor('compliance_review'));
        self::assertSame([], $catalogue->getSectionsFor('backup'));
        self::assertSame([], $catalogue->getSectionsFor('network_security'));
        self::assertSame([], $catalogue->getSectionsFor(''));
        self::assertSame([], $catalogue->getSectionsFor('___totally_unknown___'));
    }
}
