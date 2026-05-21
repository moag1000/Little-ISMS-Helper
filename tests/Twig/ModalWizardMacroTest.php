<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * Smoke tests for the `_fa_modal_wizard.html.twig` macro.
 *
 * Verifies that the render(config) API produces the expected BEM markup
 * for all major configuration combinations.
 *
 * The Twig environment is augmented with the worktree templates directory
 * so that the test can locate the macro regardless of whether the template
 * has been merged into the main repo templates directory yet.
 */
final class ModalWizardMacroTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');

        // Add the worktree templates directory to the loader chain so that
        // the macro is discoverable when running tests before the PR merges.
        $worktreeTemplates = dirname(__DIR__, 2) . '/templates';
        $currentLoader = $this->twig->getLoader();

        if ($currentLoader instanceof ChainLoader) {
            $currentLoader->addLoader(new FilesystemLoader($worktreeTemplates));
        } else {
            $chain = new ChainLoader([$currentLoader, new FilesystemLoader($worktreeTemplates)]);
            $this->twig->setLoader($chain);
        }
    }

    // ── Structural tests ───────────────────────────────────────────────────

    #[Test]
    public function rendersModalRootWithWizardModifier(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        self::assertStringContainsString('class="fa-modal fa-modal--wizard"', $output);
        self::assertStringContainsString('role="dialog"', $output);
        self::assertStringContainsString('aria-modal="true"', $output);
    }

    #[Test]
    public function rendersModalIdAttribute(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        self::assertStringContainsString('id="test-wizard"', $output);
    }

    #[Test]
    public function rendersTitleAndSubtitle(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        self::assertStringContainsString('Datenpanne melden', $output);
        self::assertStringContainsString('DSGVO Art. 33/34', $output);
    }

    #[Test]
    public function rendersWithoutSubtitleWhenOmitted(): void
    {
        $config = $this->threeStepConfig();
        unset($config['subtitle']);

        $output = $this->renderMacro($config);

        self::assertStringContainsString('Datenpanne melden', $output);
        // Subtitle paragraph must not appear; only the h2 is inside the heading div
        self::assertStringNotContainsString('DSGVO Art. 33/34', $output);
    }

    // ── Pip-rail tests ─────────────────────────────────────────────────────

    #[Test]
    public function rendersPipRailWithCorrectStepCount(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        // Three pips in the rail
        self::assertSame(
            3,
            substr_count($output, 'fa-modal__wizard-pip '),
            'Expected exactly 3 pip elements for a 3-step wizard.',
        );
    }

    #[Test]
    public function rendersFirstPipAsCurrentByDefault(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        self::assertStringContainsString('fa-modal__wizard-pip--current', $output);
        self::assertStringContainsString('aria-current="step"', $output);
    }

    #[Test]
    public function respectsInitialStepConfigForPips(): void
    {
        $config = $this->threeStepConfig();
        $config['initialStep'] = 1;

        $output = $this->renderMacro($config);

        // Step 0 should be done, step 1 should be current
        self::assertStringContainsString('fa-modal__wizard-pip--done', $output);
        self::assertStringContainsString('fa-modal__wizard-pip--current', $output);
    }

    // ── Step tests ─────────────────────────────────────────────────────────

    #[Test]
    public function rendersFirstStepAsActiveByDefault(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        self::assertStringContainsString(
            'fa-modal__wizard-step is-active',
            $output,
        );
    }

    #[Test]
    public function respectsInitialStepConfigForActiveStep(): void
    {
        $config = $this->threeStepConfig();
        $config['initialStep'] = 2;

        $output = $this->renderMacro($config);

        // Only the third step wrapper should have is-active
        $activeCount = substr_count($output, 'is-active');
        self::assertSame(1, $activeCount, 'Only one step should be active.');
    }

    #[Test]
    public function rendersStepBodyContent(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        // Body content from step 1
        self::assertStringContainsString('name="severity"', $output);
        // Body content from step 2
        self::assertStringContainsString('name="notification"', $output);
    }

    // ── Validation hint tests ──────────────────────────────────────────────

    #[Test]
    public function rendersValidationHintPassthrough(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        self::assertStringContainsString('Schweregrad auswählen', $output);
        self::assertStringContainsString('fa-modal__wizard-validation', $output);
    }

    #[Test]
    public function rendersDefaultValidationHintWhenStepHasNoHint(): void
    {
        $config = $this->threeStepConfig();
        unset($config['steps'][0]['validationHint']);

        $output = $this->renderMacro($config);

        self::assertStringContainsString(
            'Bitte alle Pflichtfelder ausfüllen',
            $output,
        );
    }

    #[Test]
    public function rendersStepDataRequiredAttribute(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        // Steps with required: true have data-step-required="true"
        self::assertStringContainsString('data-step-required="true"', $output);
        // Step with required: false has data-step-required="false"
        self::assertStringContainsString('data-step-required="false"', $output);
    }

    // ── Action button tests ────────────────────────────────────────────────

    #[Test]
    public function rendersSubmitButtonWithPrimaryVariantByDefault(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        self::assertStringContainsString('fa-cyber-btn--primary', $output);
        self::assertStringContainsString('Absenden', $output);
    }

    #[Test]
    public function rendersSubmitButtonWithDangerVariant(): void
    {
        $config = $this->threeStepConfig();
        $config['actions']['submit']['variant'] = 'danger';
        $config['actions']['submit']['label'] = 'Datenpanne erfassen';

        $output = $this->renderMacro($config);

        self::assertStringContainsString('fa-cyber-btn--danger', $output);
        self::assertStringContainsString('Datenpanne erfassen', $output);
    }

    #[Test]
    public function rendersCancelButton(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        self::assertStringContainsString('fa-cyber-btn--ghost', $output);
        self::assertStringContainsString('Abbrechen', $output);
    }

    #[Test]
    public function rendersBackAndNextButtons(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        self::assertStringContainsString('Zurück', $output);
        self::assertStringContainsString('Weiter', $output);
    }

    // ── Stimulus wiring tests ──────────────────────────────────────────────

    #[Test]
    public function wiresStimulusControllerAttribute(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        self::assertStringContainsString('data-controller="modal-wizard"', $output);
        self::assertStringContainsString('data-modal-wizard-current-step-value="0"', $output);
        self::assertStringContainsString('data-modal-wizard-total-steps-value="3"', $output);
    }

    #[Test]
    public function respectsCustomStimulusController(): void
    {
        $config = $this->threeStepConfig();
        $config['stimulusController'] = 'custom-wizard';

        $output = $this->renderMacro($config);

        self::assertStringContainsString('data-controller="custom-wizard"', $output);
        self::assertStringContainsString('data-custom-wizard-target="step"', $output);
    }

    // ── A11y tests ────────────────────────────────────────────────────────

    #[Test]
    public function rendersAriaLabelledByMatchingHeadingId(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        self::assertStringContainsString('aria-labelledby="test-wizard-title"', $output);
        self::assertStringContainsString('id="test-wizard-title"', $output);
    }

    #[Test]
    public function rendersAriaLiveOnValidationBanner(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        self::assertStringContainsString('aria-live="polite"', $output);
    }

    #[Test]
    public function doesNotContainBiIcons(): void
    {
        $output = $this->renderMacro($this->threeStepConfig());

        self::assertStringNotContainsString(
            'bi bi-',
            $output,
            'Bootstrap bi-* icon classes are forbidden — use fa-icon fa-icon--* instead.',
        );
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $config
     */
    private function renderMacro(array $config): string
    {
        $template = '{% import "_components/_fa_modal_wizard.html.twig" as _fa_mw %}{{ _fa_mw.render(config) }}';

        return $this->twig->createTemplate($template)->render(['config' => $config]);
    }

    /**
     * @return array<string, mixed>
     */
    private function threeStepConfig(): array
    {
        return [
            'id'       => 'test-wizard',
            'title'    => 'Datenpanne melden',
            'subtitle' => 'DSGVO Art. 33/34',
            'steps'    => [
                [
                    'id'             => 'step-severity',
                    'label'          => 'Schweregrad',
                    'title'          => '1. Schweregradeinstufung',
                    'required'       => true,
                    'validationHint' => 'Schweregrad auswählen und Systemanzahl angeben.',
                    'body'           => '<select name="severity" required><option value="">—</option><option>Hoch</option></select>',
                ],
                [
                    'id'             => 'step-notification',
                    'label'          => 'Meldepflicht',
                    'title'          => '2. Meldepflicht',
                    'required'       => true,
                    'validationHint' => 'Option auswählen.',
                    'body'           => '<input type="radio" name="notification" value="yes" required>',
                ],
                [
                    'id'       => 'step-review',
                    'label'    => 'Prüfen',
                    'title'    => '3. Prüfen & Absenden',
                    'required' => false,
                    'body'     => '<p>Zusammenfassung</p>',
                ],
            ],
            'actions'  => [
                'cancel' => ['label' => 'Abbrechen'],
                'submit' => ['label' => 'Absenden', 'variant' => 'primary'],
            ],
        ];
    }
}
