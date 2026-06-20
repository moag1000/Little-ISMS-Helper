<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Service\PolicyWizard\SectionExtension\SectionExtension;
use App\Service\PolicyWizard\SectionExtension\TisaxSectionCatalogue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see TisaxSectionCatalogue}.
 *
 * Mirrors {@see BsiSectionCatalogueTest} / {@see Nis2SectionCatalogueTest} in shape.
 */
final class TisaxSectionCatalogueTest extends TestCase
{
    private TisaxSectionCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->catalogue = new TisaxSectionCatalogue();
    }

    #[Test]
    public function getStandardReturnsTisax(): void
    {
        self::assertSame('tisax', $this->catalogue->getStandard());
    }

    #[Test]
    public function sectionsForMappedTopicReturnsExtension(): void
    {
        // 'access_control' is mapped: 4.1.1, 4.1.2
        $sections = $this->catalogue->sectionsForTopic('access_control');

        self::assertCount(1, $sections);
        $ext = $sections[0];
        self::assertInstanceOf(SectionExtension::class, $ext);
        self::assertSame('tisax', $ext->standard);
        self::assertSame('tisax_extension', $ext->sectionKey);
        self::assertSame('body_extension', $ext->renderMode);
        self::assertSame('ciso', $ext->approvalRole);
        self::assertNotEmpty($ext->controlRefs);
        // 4.1.1 and 4.1.2 both map to access_control
        self::assertContains('4.1.1', $ext->controlRefs);
        self::assertNotEmpty($ext->bodyTranslationKey);
        self::assertStringStartsWith('policy.iso27001.', $ext->bodyTranslationKey);
    }

    #[Test]
    public function sectionsForUnmappedTopicReturnsEmptyArray(): void
    {
        self::assertSame([], $this->catalogue->sectionsForTopic('non_existent_topic'));
        self::assertSame([], $this->catalogue->sectionsForTopic('project_management'));
        // mobile_device has no TISAX extension
        self::assertSame([], $this->catalogue->sectionsForTopic('mobile_device'));
    }

    #[Test]
    public function incidentManagementTopicMapsToTisaxRefs(): void
    {
        $sections = $this->catalogue->sectionsForTopic('incident_management');
        self::assertCount(1, $sections);
        // 1.6.1 and 1.6.2 cover A.5.24 (incident)
        self::assertContains('1.6.1', $sections[0]->controlRefs);
    }

    #[Test]
    public function supplierRelationshipsTopicMapsTisaxRefs(): void
    {
        $sections = $this->catalogue->sectionsForTopic('supplier_relationships');
        self::assertCount(1, $sections);
        self::assertContains('6.1.1', $sections[0]->controlRefs);
    }

    #[Test]
    public function physicalSecurityTopicMapsToTisaxRefs(): void
    {
        $sections = $this->catalogue->sectionsForTopic('physical_security');
        self::assertCount(1, $sections);
        self::assertContains('3.1.1', $sections[0]->controlRefs);
    }

    #[Test]
    public function allSectionsHaveValidTranslationKeyPattern(): void
    {
        $topics = [
            'top_level', 'asset_management', 'access_control', 'identity_management',
            'authentication_information', 'hr_security', 'physical_security',
            'incident_management', 'continuity', 'backup', 'supplier_relationships',
            'secure_development', 'logging', 'network_security', 'cryptography',
        ];
        foreach ($topics as $topic) {
            $sections = $this->catalogue->sectionsForTopic($topic);
            self::assertNotEmpty($sections, "Topic '$topic' must return at least one section");
            self::assertMatchesRegularExpression(
                '/^policy\.iso27001\.[a-z_]+\.v1\.tisax_extension\.body$/',
                $sections[0]->bodyTranslationKey,
                "Invalid bodyTranslationKey for topic '$topic'",
            );
        }
    }

    #[Test]
    public function allControlRefsAreTisaxNumberFormat(): void
    {
        // All controlRefs should be in TISAX number format (e.g. '1.1.1', '4.2.1', '8.1.1')
        $topics = [
            'top_level', 'asset_management', 'access_control', 'incident_management',
            'supplier_relationships', 'physical_security', 'backup', 'cryptography',
        ];
        foreach ($topics as $topic) {
            $sections = $this->catalogue->sectionsForTopic($topic);
            foreach ($sections as $section) {
                foreach ($section->controlRefs as $ref) {
                    self::assertMatchesRegularExpression(
                        '/^\d+\.\d+(\.\d+)?$/',
                        $ref,
                        "controlRef '$ref' in topic '$topic' must match TISAX number format",
                    );
                }
            }
        }
    }
}
