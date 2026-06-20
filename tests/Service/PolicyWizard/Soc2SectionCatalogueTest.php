<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Service\PolicyWizard\SectionExtension\Soc2SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Soc2SectionCatalogue}.
 *
 * Mirrors {@see Nis2SectionCatalogueTest} / {@see BsiSectionCatalogueTest}.
 *
 * controlRefs use AICPA Trust Services Criteria 2017 (revised 2022) ids,
 * e.g. CC6.1, CC7.2, A1.2, C1.1, P1.
 */
final class Soc2SectionCatalogueTest extends TestCase
{
    #[Test]
    public function getStandardReturnsSoc2(): void
    {
        self::assertSame('soc2', (new Soc2SectionCatalogue())->getStandard());
    }

    #[Test]
    public function sectionsForAccessControlReturnsSoc2BodyExtension(): void
    {
        $catalogue = new Soc2SectionCatalogue();
        $sections = $catalogue->sectionsForTopic('access_control');

        self::assertNotEmpty($sections);
        $first = $sections[0];
        self::assertInstanceOf(SectionExtension::class, $first);
        self::assertSame('soc2', $first->standard);
        self::assertNotEmpty($first->controlRefs);
        // access_control → CC6.1 (Logical Access Security)
        self::assertContains('CC6.1', $first->controlRefs);
        self::assertSame('body_extension', $first->renderMode);
        self::assertSame('ciso', $first->approvalRole);
        self::assertNotEmpty($first->bodyTranslationKey);
        self::assertStringStartsWith('policy.iso27001.access_control.v1.soc2_extension', $first->bodyTranslationKey);
    }

    #[Test]
    public function sectionsForTopicWithNoSoc2AdditionReturnsEmptyList(): void
    {
        $catalogue = new Soc2SectionCatalogue();
        // information_classification has no direct SOC 2 TSC criterion
        self::assertSame([], $catalogue->sectionsForTopic('information_classification'));
        self::assertSame([], $catalogue->sectionsForTopic('project_management'));
        self::assertSame([], $catalogue->sectionsForTopic('does_not_exist'));
    }

    #[Test]
    public function allTopicsInSectionsConstHaveAtLeastOneControlRef(): void
    {
        $catalogue = new Soc2SectionCatalogue();
        // Topics we KNOW should have SOC 2 TSC coverage
        $topicsWithCoverage = [
            'access_control',
            'identity_management',
            'authentication_information',
            'physical_security',
            'logging',
            'malware',
            'patch_management',
            'incident_management',
            'supplier_relationships',
            'continuity',
            'backup',
            'cryptography',
            'privacy_pii',
            'secure_development',
            'network_security',
            'top_level',
        ];
        foreach ($topicsWithCoverage as $topic) {
            $sections = $catalogue->sectionsForTopic($topic);
            self::assertNotEmpty($sections, "Expected SOC 2 sections for topic '$topic'");
            foreach ($sections as $section) {
                self::assertNotEmpty($section->controlRefs, "No controlRefs for '$topic'");
                foreach ($section->controlRefs as $ref) {
                    self::assertMatchesRegularExpression(
                        '/^(CC|A|C|PI|P)\d+(\.\d+)?$/',
                        $ref,
                        "ControlRef '$ref' in '$topic' should be a TSC id (CC#.#, A#.#, C#.#, PI#.#, P#)",
                    );
                }
            }
        }
    }
}
