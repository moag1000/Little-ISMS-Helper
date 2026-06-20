<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Service\PolicyWizard\SectionExtension\BsiSectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BsiSectionCatalogueTest extends TestCase
{
    private BsiSectionCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->catalogue = new BsiSectionCatalogue();
    }

    #[Test]
    public function getStandardReturnsBsi(): void
    {
        self::assertSame('bsi', $this->catalogue->getStandard());
    }

    #[Test]
    public function sectionsForMappedTopicReturnsExtension(): void
    {
        $sections = $this->catalogue->sectionsForTopic('incident_management');

        self::assertCount(1, $sections);
        $ext = $sections[0];
        self::assertInstanceOf(SectionExtension::class, $ext);
        self::assertSame('bsi', $ext->standard);
        self::assertSame('bsi_extension', $ext->sectionKey);
        self::assertSame('body_extension', $ext->renderMode);
        self::assertSame('ciso', $ext->approvalRole);
        self::assertNotEmpty($ext->controlRefs);
        self::assertContains('DER.2.1', $ext->controlRefs);
        self::assertNotEmpty($ext->bodyTranslationKey);
        self::assertStringStartsWith('policy.iso27001.', $ext->bodyTranslationKey);
    }

    #[Test]
    public function sectionsForUnmappedTopicReturnsEmptyArray(): void
    {
        self::assertSame([], $this->catalogue->sectionsForTopic('non_existent_topic'));
        self::assertSame([], $this->catalogue->sectionsForTopic('project_management'));
        self::assertSame([], $this->catalogue->sectionsForTopic('information_classification'));
    }

    #[Test]
    public function cryptographyTopicMapsToCon1(): void
    {
        $sections = $this->catalogue->sectionsForTopic('cryptography');
        self::assertCount(1, $sections);
        self::assertContains('CON.1', $sections[0]->controlRefs);
    }

    #[Test]
    public function accessControlTopicMapsToOrp4(): void
    {
        $sections = $this->catalogue->sectionsForTopic('access_control');
        self::assertCount(1, $sections);
        self::assertContains('ORP.4', $sections[0]->controlRefs);
    }

    #[Test]
    public function allSectionsHaveValidTranslationKeyPattern(): void
    {
        // Spot-check several topics
        $topics = ['top_level', 'backup', 'malware', 'patch_management', 'supplier_relationships', 'network_security'];
        foreach ($topics as $topic) {
            $sections = $this->catalogue->sectionsForTopic($topic);
            self::assertNotEmpty($sections, "Topic '$topic' must return at least one section");
            self::assertMatchesRegularExpression(
                '/^policy\.iso27001\.[a-z_]+\.v1\.bsi_extension\.body$/',
                $sections[0]->bodyTranslationKey,
                "Invalid bodyTranslationKey for topic '$topic'",
            );
        }
    }
}
