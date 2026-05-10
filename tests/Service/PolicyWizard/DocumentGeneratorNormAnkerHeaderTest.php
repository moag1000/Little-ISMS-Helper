<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\PolicyTemplate;
use App\Repository\ControlRepository;
use App\Repository\DocumentControlLinkRepository;
use App\Repository\DocumentRepository;
use App\Repository\DocumentSectionRepository;
use App\Repository\PolicyTemplateRepository;
use App\Repository\TagRepository;
use App\Service\PolicyWizard\DocumentGenerator;
use App\Service\PolicyWizard\VariableCollector;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Compliance-Manager / Auditor-External Wish — verifies
 * {@see DocumentGenerator::prependNormAnkerHeader} renders a
 * prominent norm-anchor header at the top of every generated body.
 *
 * The header surfaces:
 *   - Linked Annex A controls (ISO 27001:2022)
 *   - Linked BSI Bausteine (200-2)
 *   - Linked DORA articles
 *   - DORA-Stand stamp (`2025-01-17`) when DORA-relevant
 *   - Climate-Change-Amd. 1:2024 marker (ISO top_level only)
 *
 * No tenant variables flow into the header — the generator owns the
 * full text — so the leak detector cannot trip on it.
 */
#[AllowMockObjectsWithoutExpectations]
final class DocumentGeneratorNormAnkerHeaderTest extends TestCase
{
    private function makeGenerator(): DocumentGenerator
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $templateRepo = $this->createMock(PolicyTemplateRepository::class);
        $controlRepo = $this->createMock(ControlRepository::class);
        $dclRepo = $this->createMock(DocumentControlLinkRepository::class);
        $documentRepo = $this->createMock(DocumentRepository::class);
        $tagRepo = $this->createMock(TagRepository::class);
        $variableCollector = $this->createMock(VariableCollector::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $sectionRepo = $this->createMock(DocumentSectionRepository::class);

        return new DocumentGenerator(
            $em,
            $templateRepo,
            $controlRepo,
            $dclRepo,
            $documentRepo,
            $tagRepo,
            $variableCollector,
            $translator,
            $sectionRepo,
        );
    }

    private function makeTemplate(
        string $standard,
        string $topic,
        ?array $annexA = null,
        ?array $bausteine = null,
        ?array $doraArticles = null,
        bool $climate = false,
    ): PolicyTemplate {
        $template = new PolicyTemplate();
        $template->setKey($standard . '.' . $topic);
        $template->setStandard($standard);
        $template->setTopic($topic);
        $template->setVersion(1);
        $template->setLinkedAnnexAControls($annexA);
        $template->setLinkedBausteine($bausteine);
        $template->setLinkedDoraArticles($doraArticles);
        $template->setClimateChangeWording($climate);
        return $template;
    }

    #[Test]
    public function testHeaderListsAnnexAAndBausteineAndDoraArticles(): void
    {
        $template = $this->makeTemplate(
            standard: 'iso27001',
            topic: 'access_control',
            annexA: ['A.5.15', 'A.8.3'],
            bausteine: ['ORP.4'],
            doraArticles: ['Art. 9'],
        );

        $generator = $this->makeGenerator();
        $body = "# Access Control Policy\n\nBody content here.";
        $output = $generator->prependNormAnkerHeader($template, $body);

        self::assertStringContainsString('**Norm-Anker:**', $output);
        self::assertStringContainsString('ISO 27001:2022 A.5.15, A.8.3', $output);
        self::assertStringContainsString('BSI 200-2 ORP.4', $output);
        self::assertStringContainsString('DORA Art. 9', $output);
        // Body must remain intact AFTER the header.
        self::assertStringContainsString('# Access Control Policy', $output);
        self::assertStringStartsWith('> **Norm-Anker:**', $output);
    }

    #[Test]
    public function testHeaderEmitsDoraStandForDoraTemplates(): void
    {
        $template = $this->makeTemplate(
            standard: 'dora',
            topic: 'ict_risk_management',
            doraArticles: ['Art. 6', 'Art. 9'],
        );

        $generator = $this->makeGenerator();
        $output = $generator->prependNormAnkerHeader($template, 'body');

        self::assertStringContainsString('DORA-Stand: 2025-01-17', $output);
    }

    #[Test]
    public function testHeaderEmitsClimateMarkerOnIsoTopLevel(): void
    {
        $template = $this->makeTemplate(
            standard: 'iso27001',
            topic: 'top_level',
            annexA: ['A.5.1'],
            climate: true,
        );

        $generator = $this->makeGenerator();
        $output = $generator->prependNormAnkerHeader($template, 'body');

        self::assertStringContainsString('Climate-Change Amd. 1:2024 angewandt', $output);
        self::assertStringContainsString('A.5.1', $output);
    }

    #[Test]
    public function testHeaderSkipsClimateOnNonTopLevel(): void
    {
        $template = $this->makeTemplate(
            standard: 'iso27001',
            topic: 'access_control',
            annexA: ['A.5.15'],
            climate: true,
        );

        $generator = $this->makeGenerator();
        $output = $generator->prependNormAnkerHeader($template, 'body');

        self::assertStringNotContainsString('Climate-Change Amd. 1:2024', $output);
    }

    #[Test]
    public function testHeaderIsSuppressedWhenNoLinkedRefs(): void
    {
        $template = $this->makeTemplate(
            standard: 'iso27001',
            topic: 'top_level',
        );

        $generator = $this->makeGenerator();
        $output = $generator->prependNormAnkerHeader($template, 'body content');

        // Empty header would be noise — generator returns the body unchanged.
        self::assertSame('body content', $output);
    }

    #[Test]
    public function testHeaderHandlesPartialLinkage(): void
    {
        // Only Annex A linked — header lists ISO segment only, no BSI / DORA.
        $template = $this->makeTemplate(
            standard: 'iso27001',
            topic: 'access_control',
            annexA: ['A.5.23'],
        );

        $generator = $this->makeGenerator();
        $output = $generator->prependNormAnkerHeader($template, 'body');

        self::assertStringContainsString('ISO 27001:2022 A.5.23', $output);
        self::assertStringNotContainsString('BSI 200-2', $output);
        self::assertStringNotContainsString('DORA ', $output);
    }

    #[Test]
    public function testHeaderFiltersInvalidEntries(): void
    {
        // Mixed-type / empty refs must be filtered.
        $template = $this->makeTemplate(
            standard: 'bsi',
            topic: 'top_level',
            bausteine: ['ORP.1', '', 'ORP.2'],
        );

        $generator = $this->makeGenerator();
        $output = $generator->prependNormAnkerHeader($template, 'body');

        self::assertStringContainsString('BSI 200-2 ORP.1, ORP.2', $output);
    }
}
