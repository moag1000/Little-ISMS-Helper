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
        // form-validation controller is composed alongside form-layout so the
        // collapsed-section-reveal pattern works on validation errors (PR #718).
        self::assertStringContainsString('data-controller="form-layout form-validation"', $output);
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

    /**
     * Regression test for: all-pending form (new entity) MUST render all section bodies.
     *
     * Bug: the old conditional skipped the body when sectionStatus == 'pending',
     * causing every field to be absent from the HTML on new-entity forms.
     * Symptom: Symfony Form "Unreachable field" exception on POST.
     */
    #[Test]
    public function allPendingSectionsRenderTheirBodies(): void
    {
        $config = [
            'title'    => 'Neues Formular',
            'sections' => [
                [
                    'id'     => 'sec-a',
                    'title'  => 'Basis',
                    'status' => 'pending',
                    'fields' => 3,
                    'filled' => 0,
                    'body'   => '<input type="text" name="email">',
                ],
                [
                    'id'     => 'sec-b',
                    'title'  => 'Details',
                    'status' => 'pending',
                    'fields' => 2,
                    'filled' => 0,
                    'body'   => '<input type="text" name="phone">',
                ],
            ],
        ];

        $output = $this->renderMacro($config);

        // Both section bodies MUST be present in markup (visual collapse is CSS-only)
        $bodyCount = substr_count($output, 'fa-form-section__body');
        self::assertSame(2, $bodyCount, 'Each pending section must have a .fa-form-section__body in the DOM');

        // Both input fields MUST be present so form POST works
        self::assertStringContainsString('name="email"', $output);
        self::assertStringContainsString('name="phone"', $output);

        // Collapsed sections carry the --collapsed CSS class (visual only, not structural)
        self::assertStringContainsString('fa-form-section--collapsed', $output);
    }

    /**
     * Done sections must also have their body in the DOM so the user can
     * re-open and edit them (collapse is CSS-only via --collapsed class).
     */
    #[Test]
    public function doneSectionBodyIsAlwaysRendered(): void
    {
        $config = $this->fullConfig();
        // sec-1 is status=done; add a body so we can assert it appears
        $config['sections'][0]['body'] = '<input type="text" name="done_field">';

        $output = $this->renderMacro($config);

        self::assertStringContainsString('name="done_field"', $output,
            'A done section\'s body must be in the DOM — collapse is visual-only via CSS');
    }

    /**
     * Every section (regardless of status) must produce a .fa-form-section__body
     * element so the Stimulus controller can toggle it via the --collapsed modifier.
     */
    #[Test]
    public function everySectionStatusProducesABodyElement(): void
    {
        $config = [
            'title'    => 'All statuses',
            'sections' => [
                ['id' => 's-done',    'title' => 'Done',    'status' => 'done',    'fields' => 1, 'filled' => 1],
                ['id' => 's-current', 'title' => 'Current', 'status' => 'current', 'fields' => 1, 'filled' => 0],
                ['id' => 's-error',   'title' => 'Error',   'status' => 'error',   'fields' => 1, 'filled' => 0],
                ['id' => 's-pending', 'title' => 'Pending', 'status' => 'pending', 'fields' => 1, 'filled' => 0],
            ],
        ];

        $output = $this->renderMacro($config);

        $bodyCount = substr_count($output, 'fa-form-section__body');
        self::assertSame(4, $bodyCount, 'All 4 section statuses must render a .fa-form-section__body');
    }

    #[Test]
    public function rendersWithCustomStimulusController(): void
    {
        $config = $this->fullConfig();
        $config['stimulusController'] = 'my-custom-form';

        $output = $this->renderMacro($config);

        self::assertStringContainsString('data-controller="my-custom-form form-validation"', $output);
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
