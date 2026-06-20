<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Service\PolicyWizard\SectionExtension\Iso27701SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see Iso27701SectionCatalogue}.
 *
 * ISO/IEC 27701 is a PIMS (Privacy Information Management System) extension
 * of ISO 27001/27002. The catalogue maps ISO 27001 topic keys to 27701
 * clause-grounded body-extension sections, with DPO approval (privacy-owned).
 */
final class Iso27701SectionCatalogueTest extends TestCase
{
    #[Test]
    public function getStandardReturnsIso27701(): void
    {
        self::assertSame('iso27701', (new Iso27701SectionCatalogue())->getStandard());
    }

    #[Test]
    public function sectionsForPrivacyPiiReturnsIso27701BodyExtension(): void
    {
        $catalogue = new Iso27701SectionCatalogue();
        $sections = $catalogue->sectionsForTopic('privacy_pii');

        self::assertNotEmpty($sections, 'privacy_pii must have ISO 27701 extensions (core PIMS topic)');

        $first = $sections[0];
        self::assertInstanceOf(SectionExtension::class, $first);
        self::assertSame('iso27701', $first->standard);
        self::assertNotEmpty($first->controlRefs, 'privacy_pii must have non-empty controlRefs');
        self::assertSame('body_extension', $first->renderMode);
        self::assertSame('dpo', $first->approvalRole, 'ISO 27701 PIMS extensions are DPO-owned');
        self::assertNotEmpty($first->bodyTranslationKey);
        self::assertStringStartsWith(
            'policy.iso27001.privacy_pii.v1.iso27701_extension',
            $first->bodyTranslationKey,
        );
    }

    #[Test]
    public function sectionsForPrivacyPiiHasValidClauseRefs(): void
    {
        $catalogue = new Iso27701SectionCatalogue();
        $sections = $catalogue->sectionsForTopic('privacy_pii');

        self::assertNotEmpty($sections);
        foreach ($sections as $section) {
            foreach ($section->controlRefs as $ref) {
                // ISO 27701 refs follow the DB-canonical 27701-A.7.x.x / 27701-B.8.x.x
                // OR clause-only form (e.g. 5.2, 6.x, 7.2.x, 7.3.x, 8.x)
                self::assertMatchesRegularExpression(
                    '/^(27701-[AB]\.\d+(\.\d+)*|\d+\.\d+(\.\d+)*)$/',
                    $ref,
                    "controlRef '$ref' must be a valid ISO 27701 clause reference",
                );
            }
        }
    }

    #[Test]
    public function sectionsForTopicWithNoIso27701AdditionReturnsEmptyList(): void
    {
        $catalogue = new Iso27701SectionCatalogue();

        // Topics that have no meaningful PIMS extension
        self::assertSame([], $catalogue->sectionsForTopic('network_security'));
        self::assertSame([], $catalogue->sectionsForTopic('malware'));
        self::assertSame([], $catalogue->sectionsForTopic('patch_management'));
        self::assertSame([], $catalogue->sectionsForTopic('does_not_exist'));
        self::assertSame([], $catalogue->sectionsForTopic('backup'));
    }

    #[Test]
    public function allCoveredTopicsReturnNonEmptyControlRefs(): void
    {
        $catalogue = new Iso27701SectionCatalogue();

        // Topics 27701 genuinely augments (privacy-adjacent ISO 27001 topics)
        $topicsWithCoverage = [
            'privacy_pii',
            'information_classification',
            'supplier_relationships',
            'access_control',
            'incident_management',
            'hr_security',
            'acceptable_use',
            'top_level',
        ];

        foreach ($topicsWithCoverage as $topic) {
            $sections = $catalogue->sectionsForTopic($topic);
            self::assertNotEmpty($sections, "Expected ISO 27701 sections for topic '$topic'");
            foreach ($sections as $section) {
                self::assertNotEmpty($section->controlRefs, "No controlRefs for '$topic'");
                self::assertSame('iso27701', $section->standard);
                self::assertSame('body_extension', $section->renderMode);
                self::assertSame('dpo', $section->approvalRole);
            }
        }
    }

    #[Test]
    public function sectionKeyIsIso27701Extension(): void
    {
        $catalogue = new Iso27701SectionCatalogue();
        $sections = $catalogue->sectionsForTopic('privacy_pii');

        self::assertNotEmpty($sections);
        self::assertSame('iso27701_extension', $sections[0]->sectionKey);
    }
}
