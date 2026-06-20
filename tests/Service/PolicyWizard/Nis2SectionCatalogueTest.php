<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Service\PolicyWizard\SectionExtension\Nis2SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Nis2SectionCatalogueTest extends TestCase
{
    #[Test]
    public function getStandardReturnsNis2(): void
    {
        self::assertSame('nis2', (new Nis2SectionCatalogue())->getStandard());
    }

    #[Test]
    public function sectionsForIncidentManagementReturnsNis2BodyExtension(): void
    {
        $catalogue = new Nis2SectionCatalogue();
        $sections = $catalogue->sectionsForTopic('incident_management');
        self::assertNotEmpty($sections);
        $first = $sections[0];
        self::assertInstanceOf(SectionExtension::class, $first);
        self::assertSame('nis2', $first->standard);
        self::assertContains('NIS2-ART21-B', $first->controlRefs);
        self::assertSame('body_extension', $first->renderMode);
        self::assertSame('ciso', $first->approvalRole);
        self::assertNotEmpty($first->bodyTranslationKey);
        self::assertStringStartsWith('policy.iso27001.incident_management.v1.nis2_extension', $first->bodyTranslationKey);
    }

    #[Test]
    public function sectionsForTopicWithNoNis2AdditionReturnsEmptyList(): void
    {
        $catalogue = new Nis2SectionCatalogue();
        // information_classification has no NIS2 Art.21 coverage
        self::assertSame([], $catalogue->sectionsForTopic('information_classification'));
        self::assertSame([], $catalogue->sectionsForTopic('network_security'));
        self::assertSame([], $catalogue->sectionsForTopic('does_not_exist'));
    }

    #[Test]
    public function allTopicsInSectionsConstHaveAtLeastOneControlRef(): void
    {
        $catalogue = new Nis2SectionCatalogue();
        // Topics we KNOW should have coverage
        $topicsWithCoverage = ['incident_management', 'continuity', 'backup', 'supplier_relationships',
                               'secure_development', 'cryptography', 'access_control', 'hr_security',
                               'authentication_information', 'asset_management'];
        foreach ($topicsWithCoverage as $topic) {
            $sections = $catalogue->sectionsForTopic($topic);
            self::assertNotEmpty($sections, "Expected NIS2 sections for topic '$topic'");
            foreach ($sections as $section) {
                self::assertNotEmpty($section->controlRefs, "No controlRefs for '$topic'");
                foreach ($section->controlRefs as $ref) {
                    self::assertStringStartsWith('NIS2-ART21-', $ref, "ControlRef '$ref' in '$topic' should use NIS2-ART21-X format");
                }
            }
        }
    }
}
