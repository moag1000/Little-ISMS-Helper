<?php

declare(strict_types=1);

namespace App\Tests\Twig\Macro;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Audit-S5 P-13 — render tests for `_fa_severity_choice.html.twig`.
 */
final class FaSeverityChoiceTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    #[Test]
    public function rendersAllFourStages(): void
    {
        $output = $this->render([
            'name' => 'incident[severity]',
            'selected' => null,
            'translation_prefix' => 'incident',
            'translation_domain' => 'incident',
        ]);

        foreach (['low', 'medium', 'high', 'critical'] as $stage) {
            $this->assertStringContainsString('value="' . $stage . '"', $output);
        }
    }

    #[Test]
    public function rendersTonePerStage(): void
    {
        $output = $this->render([
            'name' => 'incident[severity]',
            'selected' => null,
            'translation_prefix' => 'incident',
            'translation_domain' => 'incident',
        ]);

        $this->assertStringContainsString('fa-severity-choice__option--success', $output);
        $this->assertStringContainsString('fa-severity-choice__option--info', $output);
        $this->assertStringContainsString('fa-severity-choice__option--warning', $output);
        $this->assertStringContainsString('fa-severity-choice__option--danger', $output);
    }

    #[Test]
    public function marksSelectedStageWithCheckedAndIsSelectedClass(): void
    {
        $output = $this->render([
            'name' => 'incident[severity]',
            'selected' => 'high',
            'translation_prefix' => 'incident',
            'translation_domain' => 'incident',
        ]);

        $this->assertMatchesRegularExpression(
            '/value="high"\s+checked/',
            $output,
            'High option should carry the checked attribute',
        );
        // is-selected class is on the corresponding label
        $this->assertStringContainsString('fa-severity-choice__option--warning is-selected', $output);
    }

    #[Test]
    public function rendersDefinitionTextFromTranslations(): void
    {
        $output = $this->render([
            'name' => 'incident[severity]',
            'selected' => null,
            'translation_prefix' => 'incident',
            'translation_domain' => 'incident',
        ]);

        // The definition text from translations/incident.de.yaml — locale defaults
        // to DE in the test kernel. We do not assert the exact phrase verbatim
        // (translation file may evolve), only that the definition span is
        // present and non-empty for every stage.
        $this->assertStringContainsString('fa-severity-choice__definition', $output);
        // The string "Beispiel:" (Example:) appears in every German definition.
        $this->assertGreaterThanOrEqual(
            4,
            substr_count($output, 'Beispiel:') + substr_count($output, 'Example:'),
            'Each of the 4 stages should expose its definition copy.',
        );
    }

    #[Test]
    public function appliesRequiredAttributeWhenRequested(): void
    {
        $output = $this->render([
            'name' => 'incident[severity]',
            'selected' => null,
            'translation_prefix' => 'incident',
            'translation_domain' => 'incident',
            'required' => true,
        ]);

        // 4 stages × required attribute = 4 occurrences
        $this->assertSame(4, substr_count($output, 'required'));
    }

    /**
     * @param array<string, mixed> $props
     */
    private function render(array $props): string
    {
        $template = '{% import "_components/_fa_severity_choice.html.twig" as m %}{{ m.render(props) }}';
        return $this->twig->createTemplate($template)->render(['props' => $props]);
    }
}
