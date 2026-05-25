<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * S17 B4 follow-up — smoke tests for the `_fa_capa_builder.html.twig`
 * macro used by AuditFinding's `nonconformityDetails` JSON field.
 */
final class FaCapaBuilderMacroTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    #[Test]
    public function rendersStimulusControllerAndHiddenInputForEmptyValue(): void
    {
        $output = $this->renderMacro([
            'name' => 'audit_finding[nonconformityDetails]',
            'value' => null,
            'userChoices' => [],
            'translationDomain' => 'audits',
        ]);

        self::assertStringContainsString('data-controller="capa-builder"', $output);
        self::assertStringContainsString('name="audit_finding[nonconformityDetails]"', $output);
        self::assertStringContainsString('data-capa-builder-target="hiddenJsonInput"', $output);
        self::assertStringContainsString('data-capa-builder-target="methodSelect"', $output);
        self::assertStringContainsString('data-capa-builder-target="verificationMethodSelect"', $output);
        self::assertStringContainsString('data-capa-builder-target="correctionsList"', $output);
        self::assertStringContainsString('data-capa-builder-target="correctionTemplate"', $output);
        // Row-template placeholders are present (consumed client-side).
        self::assertStringContainsString('__INDEX__', $output);
        self::assertStringContainsString('__OWNER_OPTIONS__', $output);
    }

    #[Test]
    public function serializesUserChoicesAsJsonInScriptTarget(): void
    {
        $output = $this->renderMacro([
            'name' => 'audit_finding[nonconformityDetails]',
            'value' => null,
            'userChoices' => [
                ['id' => 11, 'label' => 'Alice Anderson'],
                ['id' => 22, 'label' => 'Bob Berger'],
            ],
            'translationDomain' => 'audits',
        ]);

        self::assertStringContainsString('data-capa-builder-target="userChoicesJson"', $output);
        self::assertStringContainsString('"id":11', $output);
        self::assertStringContainsString('"label":"Alice Anderson"', $output);
        self::assertStringContainsString('"id":22', $output);
    }

    #[Test]
    public function pretrasformsPopulatedValueIntoHiddenInputValue(): void
    {
        $value = [
            'rootCauseAnalysisMethod' => '5-why',
            'correctiveActions' => [
                ['description' => 'Add CI gate', 'ownerId' => 11, 'deadline' => '2026-06-30'],
            ],
            'verificationMethod' => 'document-review',
            'verificationEvidence' => 'See GitHub PR',
        ];
        $output = $this->renderMacro([
            'name' => 'audit_finding[nonconformityDetails]',
            'value' => $value,
            'userChoices' => [],
            'translationDomain' => 'audits',
        ]);

        // The macro pre-serialises the value as JSON into the hidden input —
        // controller hydrates from there on connect().
        self::assertMatchesRegularExpression('/value="[^"]*5-why[^"]*"/', $output);
        self::assertMatchesRegularExpression('/value="[^"]*document-review[^"]*"/', $output);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function renderMacro(array $args): string
    {
        $template = '{% import "_components/_fa_capa_builder.html.twig" as m %}{{ m.render(args) }}';
        return $this->twig->createTemplate($template)->render(['args' => $args]);
    }
}
