<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Service\PolicyWizard\SectionExtension\BcmSectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see BcmSectionCatalogue}.
 *
 * ISO 22301:2019 BCMS section-extension catalogue — maps ISO 27001 topic keys
 * to ISO 22301 clause refs that extend the baseline topic policy body.
 */
final class BcmSectionCatalogueTest extends TestCase
{
    private BcmSectionCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->catalogue = new BcmSectionCatalogue();
    }

    #[Test]
    public function getStandardReturnsBcm(): void
    {
        self::assertSame('bcm', $this->catalogue->getStandard());
    }

    #[Test]
    public function sectionsForContinuityTopicReturnsExtension(): void
    {
        $sections = $this->catalogue->sectionsForTopic('continuity');

        self::assertCount(1, $sections);
        $ext = $sections[0];
        self::assertInstanceOf(SectionExtension::class, $ext);
        self::assertSame('bcm', $ext->standard);
        self::assertSame('bcm_extension', $ext->sectionKey);
        self::assertSame('body_extension', $ext->renderMode);
        self::assertSame('ciso', $ext->approvalRole);
        self::assertNotEmpty($ext->controlRefs);
        // continuity → 8.2 (BIA), 8.3 (strategy), 8.4 (plans), 8.5 (exercising)
        self::assertContains('8.2', $ext->controlRefs);
        self::assertContains('8.4', $ext->controlRefs);
        self::assertNotEmpty($ext->bodyTranslationKey);
        self::assertStringStartsWith('policy.iso27001.', $ext->bodyTranslationKey);
    }

    #[Test]
    public function sectionsForUnmappedTopicReturnsEmptyArray(): void
    {
        self::assertSame([], $this->catalogue->sectionsForTopic('cryptography'));
        self::assertSame([], $this->catalogue->sectionsForTopic('non_existent_topic'));
        self::assertSame([], $this->catalogue->sectionsForTopic('information_classification'));
        self::assertSame([], $this->catalogue->sectionsForTopic('network_security'));
    }

    #[Test]
    public function backupTopicMapsToBcmExtension(): void
    {
        $sections = $this->catalogue->sectionsForTopic('backup');
        self::assertCount(1, $sections);
        $ext = $sections[0];
        self::assertSame('bcm', $ext->standard);
        self::assertNotEmpty($ext->controlRefs);
    }

    #[Test]
    public function incidentManagementTopicMapsToBcmExtension(): void
    {
        $sections = $this->catalogue->sectionsForTopic('incident_management');
        self::assertCount(1, $sections);
        $ext = $sections[0];
        self::assertSame('bcm', $ext->standard);
        // incident_management → 8.4.2 / 8.4.3 (incident response structure / continuity activation)
        self::assertNotEmpty($ext->controlRefs);
    }

    #[Test]
    public function topLevelTopicMapsToBcmExtension(): void
    {
        $sections = $this->catalogue->sectionsForTopic('top_level');
        self::assertCount(1, $sections);
        $ext = $sections[0];
        self::assertSame('bcm', $ext->standard);
        // top_level → 5.2 (BC policy), 5.1 (leadership)
        self::assertContains('5.2', $ext->controlRefs);
    }

    #[Test]
    public function supplierRelationshipsTopicMapsToBcmExtension(): void
    {
        $sections = $this->catalogue->sectionsForTopic('supplier_relationships');
        self::assertCount(1, $sections);
        $ext = $sections[0];
        self::assertSame('bcm', $ext->standard);
        // supplier_relationships → 8.3 (resource/supplier dependencies in continuity)
        self::assertContains('8.3', $ext->controlRefs);
    }

    #[Test]
    public function allSectionsHaveValidTranslationKeyPattern(): void
    {
        $topics = ['continuity', 'backup', 'incident_management', 'top_level', 'supplier_relationships'];
        foreach ($topics as $topic) {
            $sections = $this->catalogue->sectionsForTopic($topic);
            self::assertNotEmpty($sections, "Topic '$topic' must return at least one section");
            self::assertMatchesRegularExpression(
                '/^policy\.iso27001\.[a-z_]+\.v1\.bcm_extension\.body$/',
                $sections[0]->bodyTranslationKey,
                "Invalid bodyTranslationKey for topic '$topic'",
            );
        }
    }

    #[Test]
    public function allMappedSectionsHaveNonEmptyControlRefs(): void
    {
        $topics = ['continuity', 'backup', 'incident_management', 'top_level', 'supplier_relationships'];
        foreach ($topics as $topic) {
            $sections = $this->catalogue->sectionsForTopic($topic);
            self::assertNotEmpty($sections[0]->controlRefs, "Topic '$topic' controlRefs must not be empty");
            foreach ($sections[0]->controlRefs as $ref) {
                self::assertNotEmpty($ref, "Each controlRef for topic '$topic' must be non-empty");
            }
        }
    }
}
