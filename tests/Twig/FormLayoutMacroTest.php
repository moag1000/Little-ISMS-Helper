<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Smoke tests for the `_fa_form_layout.html.twig` macro.
 *
 * Verifies that the render(config) API produces the expected BEM markup
 * for all section statuses: done, current, error, pending.
 */
final class FormLayoutMacroTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    #[Test]
    public function rendersTopLevelLayoutWrapper(): void
    {
        $output = $this->renderMacro($this->fullConfig());

        self::assertStringContainsString('class="fa-form-layout"', $output);
        self::assertStringContainsString('data-controller="form-layout"', $output);
    }

    #[Test]
    public function rendersHeaderWithTitleAndSubtitle(): void
    {
        $output = $this->renderMacro($this->fullConfig());

        self::assertStringContainsString('fa-form-layout__header', $output);
        self::assertStringContainsString('Datenschutz-Folgenabschätzung', $output);
        self::assertStringContainsString('HR-Server 01', $output);
    }

    #[Test]
    public function rendersEyebrowWhenProvided(): void
    {
        $output = $this->renderMacro($this->fullConfig());

        self::assertStringContainsString('fa-form-layout__eyebrow', $output);
        self::assertStringContainsString('DSGVO Art. 35', $output);
    }

    #[Test]
    public function rendersProgressBarWithCorrectWidth(): void
    {
        $output = $this->renderMacro($this->fullConfig());

        self::assertStringContainsString('fa-form-layout__progress-bar', $output);
        self::assertStringContainsString('fa-form-layout__progress-fill', $output);
        // 3 of 5 = 60%
        self::assertMatchesRegularExpression('/width:\s*60%/', $output);
    }

    #[Test]
    public function rendersOutlineRailWithAllSections(): void
    {
        $output = $this->renderMacro($this->fullConfig());

        self::assertStringContainsString('fa-form-layout__outline', $output);
        self::assertStringContainsString('fa-form-layout__outline-list', $output);
        // All 4 section titles appear in outline
        self::assertStringContainsString('Grunddaten', $output);
        self::assertStringContainsString('Risiken', $output);
        self::assertStringContainsString('DPO-Konsultation', $output);
        self::assertStringContainsString('Freigabe', $output);
    }

    #[Test]
    public function rendersDoneSectionWithCorrectClasses(): void
    {
        $output = $this->renderMacro($this->fullConfig());

        self::assertStringContainsString('fa-form-section--done', $output);
        // Done outline item
        self::assertStringContainsString('fa-form-layout__outline-item--done', $output);
        // Done section has collapsed state (default for done)
        self::assertStringContainsString('fa-form-section--collapsed', $output);
    }

    #[Test]
    public function rendersCurrentSectionAsOpenWithCorrectClasses(): void
    {
        $output = $this->renderMacro($this->fullConfig());

        self::assertStringContainsString('fa-form-section--current', $output);
        self::assertStringContainsString('fa-form-section--open', $output);
        self::assertStringContainsString('fa-form-layout__outline-item--current', $output);
        self::assertStringContainsString('fa-form-layout__outline-item--active', $output);
    }

    #[Test]
    public function rendersErrorSectionWithCorrectClasses(): void
    {
        $output = $this->renderMacro($this->fullConfig());

        self::assertStringContainsString('fa-form-section--error', $output);
        self::assertStringContainsString('fa-form-layout__outline-item--error', $output);
        self::assertStringContainsString('DPO-Stellungnahme + Datum fehlen', $output);
    }

    #[Test]
    public function rendersFooterActionButtons(): void
    {
        $output = $this->renderMacro($this->fullConfig());

        self::assertStringContainsString('fa-form-layout__footer', $output);
        self::assertStringContainsString('Als Entwurf schließen', $output);
        self::assertStringContainsString('Nächster Abschnitt', $output);
        self::assertStringContainsString('DPIA abschließen', $output);
        // Submit button variant
        self::assertStringContainsString('fa-cyber-btn--primary', $output);
        self::assertStringContainsString('fa-cyber-btn--secondary', $output);
        self::assertStringContainsString('fa-cyber-btn--ghost', $output);
    }

    #[Test]
    public function rendersSectionBodyWhenProvided(): void
    {
        $config = $this->fullConfig();
        $config['sections'][1]['body'] = '<input type="text" name="risk_probability" value="Hoch">';

        $output = $this->renderMacro($config);

        self::assertStringContainsString('fa-form-section__body', $output);
        self::assertStringContainsString('name="risk_probability"', $output);
    }

    #[Test]
    public function rendersWithCustomStimulusController(): void
    {
        $config = $this->fullConfig();
        $config['stimulusController'] = 'my-custom-form';

        $output = $this->renderMacro($config);

        self::assertStringContainsString('data-controller="my-custom-form"', $output);
        self::assertStringContainsString('data-my-custom-form-target="section"', $output);
    }

    #[Test]
    public function rendersOutlineCountBadgePerSection(): void
    {
        $output = $this->renderMacro($this->fullConfig());

        // Done section: 5/5
        self::assertStringContainsString('5/5', $output);
        // Current section: 2/5
        self::assertStringContainsString('2/5', $output);
        // Error section: 1/3
        self::assertStringContainsString('1/3', $output);
    }

    #[Test]
    public function rendersWithoutOptionalFieldsGracefully(): void
    {
        $output = $this->renderMacro([
            'title'    => 'Minimal Form',
            'sections' => [
                ['id' => 'min-1', 'title' => 'Abschnitt 1', 'status' => 'pending', 'fields' => 3, 'filled' => 0],
            ],
        ]);

        self::assertStringContainsString('fa-form-layout', $output);
        self::assertStringContainsString('Minimal Form', $output);
        self::assertStringContainsString('Abschnitt 1', $output);
        // No eyebrow block rendered
        self::assertStringNotContainsString('fa-form-layout__eyebrow', $output);
        // No progress block rendered
        self::assertStringNotContainsString('fa-form-layout__progress', $output);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $config
     */
    private function renderMacro(array $config): string
    {
        $template = '{% import "_components/_fa_form_layout.html.twig" as _fa_form %}{{ _fa_form.render(config) }}';
        return $this->twig->createTemplate($template)->render(['config' => $config]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fullConfig(): array
    {
        return [
            'eyebrow'  => 'DSGVO Art. 35 · DPIA',
            'title'    => 'Datenschutz-Folgenabschätzung',
            'subtitle' => 'HR-Server 01',
            'progress' => ['done' => 3, 'total' => 5],
            'sections' => [
                [
                    'id'     => 'sec-1',
                    'title'  => 'Grunddaten',
                    'status' => 'done',
                    'fields' => 5,
                    'filled' => 5,
                ],
                [
                    'id'     => 'sec-6',
                    'title'  => 'Risiken',
                    'status' => 'current',
                    'fields' => 5,
                    'filled' => 2,
                ],
                [
                    'id'        => 'sec-8',
                    'title'     => 'DPO-Konsultation',
                    'status'    => 'error',
                    'fields'    => 3,
                    'filled'    => 1,
                    'errorHint' => 'DPO-Stellungnahme + Datum fehlen',
                ],
                [
                    'id'     => 'sec-10',
                    'title'  => 'Freigabe',
                    'status' => 'pending',
                    'fields' => 3,
                    'filled' => 0,
                ],
            ],
            'actions'  => [
                'close'  => ['label' => 'Als Entwurf schließen', 'variant' => 'ghost'],
                'next'   => ['label' => 'Nächster Abschnitt',    'variant' => 'secondary'],
                'submit' => ['label' => 'DPIA abschließen',      'variant' => 'primary'],
            ],
        ];
    }
}
