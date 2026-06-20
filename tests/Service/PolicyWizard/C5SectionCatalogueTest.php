<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Service\PolicyWizard\SectionExtension\C5SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class C5SectionCatalogueTest extends TestCase
{
    private C5SectionCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->catalogue = new C5SectionCatalogue();
    }

    #[Test]
    public function getStandardReturnsC5(): void
    {
        self::assertSame('c5', $this->catalogue->getStandard());
    }

    #[Test]
    public function sectionsForMappedTopicReturnsExtension(): void
    {
        $sections = $this->catalogue->sectionsForTopic('incident_management');

        self::assertCount(1, $sections);
        $ext = $sections[0];
        self::assertInstanceOf(SectionExtension::class, $ext);
        self::assertSame('c5', $ext->standard);
        self::assertSame('c5_extension', $ext->sectionKey);
        self::assertSame('body_extension', $ext->renderMode);
        self::assertSame('ciso', $ext->approvalRole);
        self::assertNotEmpty($ext->controlRefs);
        self::assertContains('SIM-01', $ext->controlRefs);
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
    public function cryptographyTopicMapsToCry01(): void
    {
        $sections = $this->catalogue->sectionsForTopic('cryptography');
        self::assertCount(1, $sections);
        self::assertContains('CRY-01', $sections[0]->controlRefs);
    }

    #[Test]
    public function accessControlTopicMapsToIdmRefs(): void
    {
        $sections = $this->catalogue->sectionsForTopic('access_control');
        self::assertCount(1, $sections);
        self::assertNotEmpty($sections[0]->controlRefs);
    }

    #[Test]
    public function allSectionsHaveValidTranslationKeyPattern(): void
    {
        $topics = ['top_level', 'incident_management', 'cryptography', 'malware', 'patch_management', 'supplier_relationships'];
        foreach ($topics as $topic) {
            $sections = $this->catalogue->sectionsForTopic($topic);
            self::assertNotEmpty($sections, "Topic '$topic' must return at least one section");
            self::assertMatchesRegularExpression(
                '/^policy\.iso27001\.[a-z_]+\.v1\.c5_extension\.body$/',
                $sections[0]->bodyTranslationKey,
                "Invalid bodyTranslationKey for topic '$topic'",
            );
        }
    }
}
