<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * Smoke tests for the `_fa_modal.html.twig` macro library (confirm + settings).
 *
 * Verifies that the render API produces the expected BEM markup, controller
 * wiring, and tone modifiers for each mode. Wizard delegation is covered by
 * the dedicated ModalWizardMacroTest.
 */
final class FaModalMacroTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');

        $worktreeTemplates = dirname(__DIR__, 2) . '/templates';
        $currentLoader = $this->twig->getLoader();

        if ($currentLoader instanceof ChainLoader) {
            $currentLoader->addLoader(new FilesystemLoader($worktreeTemplates));
        } else {
            $chain = new ChainLoader([$currentLoader, new FilesystemLoader($worktreeTemplates)]);
            $this->twig->setLoader($chain);
        }
    }

    // ── confirm() — Structural ──────────────────────────────────────────────

    #[Test]
    public function confirmRendersShellWithModeAndToneModifiers(): void
    {
        $output = $this->renderConfirm([
            'id'           => 'delete-risk',
            'title'        => 'Risiko löschen?',
            'tone'         => 'danger',
            'confirmLabel' => 'Löschen',
        ]);

        self::assertStringContainsString('class="fa-modal fa-modal--confirm fa-modal--danger"', $output);
        self::assertStringContainsString('role="alertdialog"', $output);
        self::assertStringContainsString('aria-modal="true"', $output);
        self::assertStringContainsString('id="delete-risk"', $output);
    }

    #[Test]
    public function confirmWiresFaModalStimulusControllerByDefault(): void
    {
        $output = $this->renderConfirm([
            'id'           => 'simple-confirm',
            'title'        => 'Übernehmen?',
            'confirmLabel' => 'OK',
        ]);

        self::assertMatchesRegularExpression('/data-controller="fa-modal(?!\s+fa-confirm)/', $output);
    }

    #[Test]
    public function confirmAddsFaConfirmControllerWhenTypeToConfirmSet(): void
    {
        $output = $this->renderConfirm([
            'id'            => 'typed-confirm',
            'title'         => 'API-Key widerrufen?',
            'tone'          => 'danger',
            'typeToConfirm' => 'reporting-service',
            'confirmLabel'  => 'Widerrufen',
        ]);

        self::assertStringContainsString('data-controller="fa-modal fa-confirm"', $output);
        self::assertStringContainsString('data-fa-confirm-phrase-value="reporting-service"', $output);
        self::assertStringContainsString('data-fa-confirm-target="phraseInput"', $output);
    }

    #[Test]
    public function confirmAddsCooldownDataValueWhenSet(): void
    {
        $output = $this->renderConfirm([
            'id'              => 'cooldown-confirm',
            'title'           => 'Bestätigen?',
            'tone'            => 'danger',
            'cooldownSeconds' => 5,
            'confirmLabel'    => 'OK',
        ]);

        self::assertStringContainsString('data-fa-confirm-cooldown-value="5"', $output);
    }

    #[Test]
    public function confirmRendersDiffRows(): void
    {
        $output = $this->renderConfirm([
            'id'           => 'diff-confirm',
            'title'        => 'Änderung übernehmen?',
            'confirmLabel' => 'OK',
            'diff'         => [
                ['label' => 'Aktive Verbindungen', 'value' => '14 → 0'],
                ['label' => 'Service-Status',      'value' => 'offline', 'tone' => 'danger'],
            ],
        ]);

        self::assertStringContainsString('fa-modal__diff', $output);
        self::assertSame(2, substr_count($output, 'fa-modal__diff-row'));
        self::assertStringContainsString('Aktive Verbindungen', $output);
        self::assertStringContainsString('14 → 0', $output);
        self::assertStringContainsString('color: var(--danger-strong);', $output);
    }

    #[Test]
    public function confirmRendersCsrfTokenAndAction(): void
    {
        $output = $this->renderConfirm([
            'id'            => 'csrf-confirm',
            'title'         => 'Bestätigen?',
            'confirmLabel'  => 'OK',
            'formAction'    => '/risks/12/delete',
            'formCsrfToken' => 'abc-csrf-token',
        ]);

        self::assertStringContainsString('action="/risks/12/delete"', $output);
        self::assertStringContainsString('value="abc-csrf-token"', $output);
        self::assertStringContainsString('name="_token"', $output);
    }

    #[Test]
    public function confirmAppliesToneModifierClassForAllSupportedTones(): void
    {
        foreach (['neutral', 'danger', 'warning', 'success'] as $tone) {
            $output = $this->renderConfirm([
                'id'           => 'tone-' . $tone,
                'title'        => 'Test',
                'tone'         => $tone,
                'confirmLabel' => 'OK',
            ]);

            self::assertStringContainsString(
                'fa-modal--' . $tone,
                $output,
                "Tone modifier fa-modal--{$tone} missing in output.",
            );
        }
    }

    // ── settings() — Structural ─────────────────────────────────────────────

    #[Test]
    public function settingsRendersShellWithSettingsModifier(): void
    {
        $output = $this->renderSettings([
            'id'           => 'dashboard-customize',
            'title'        => 'Dashboard anpassen',
            'body'         => '<p>toggles here</p>',
            'primaryLabel' => 'Speichern',
        ]);

        self::assertStringContainsString('class="fa-modal fa-modal--settings"', $output);
        self::assertStringContainsString('role="dialog"', $output);
        self::assertStringContainsString('data-controller="fa-modal"', $output);
        self::assertStringContainsString('toggles here', $output);
    }

    #[Test]
    public function settingsRendersWithoutFormWhenFormActionMissing(): void
    {
        $output = $this->renderSettings([
            'id'    => 'info-modal',
            'title' => 'Info',
            'body'  => '<p>just info</p>',
        ]);

        self::assertStringNotContainsString('<form', $output);
    }

    #[Test]
    public function settingsRendersWithFormWhenFormActionProvided(): void
    {
        $output = $this->renderSettings([
            'id'            => 'with-form',
            'title'         => 'Settings',
            'body'          => '<p>body</p>',
            'primaryLabel'  => 'Save',
            'formAction'    => '/settings/save',
            'formCsrfToken' => 'tok-123',
        ]);

        self::assertStringContainsString('<form method="post"', $output);
        self::assertStringContainsString('action="/settings/save"', $output);
        self::assertStringContainsString('value="tok-123"', $output);
    }

    // ── BC delegator — _fa_confirm.render() ─────────────────────────────────

    #[Test]
    public function legacyFaConfirmDelegatesToFaModalConfirmWithToneTranslation(): void
    {
        $template = '{% import "_components/_fa_confirm.html.twig" as _fa_confirm %}'
            . '{{ _fa_confirm.render({tone: "warn", title: "Test", submitLabel: "OK", id: "legacy-1"}) }}';

        $output = $this->twig->createTemplate($template)->render();

        // tone: 'warn' (legacy) → 'warning' (new)
        self::assertStringContainsString('fa-modal--warning', $output);
        self::assertStringContainsString('fa-modal--confirm', $output);
        self::assertStringContainsString('id="legacy-1"', $output);
        // submitLabel → confirmLabel
        self::assertStringContainsString('OK', $output);
    }

    #[Test]
    public function legacyNuclearToneEscalatesToDangerWithMinimumCooldown(): void
    {
        $template = '{% import "_components/_fa_confirm.html.twig" as _fa_confirm %}'
            . '{{ _fa_confirm.render({tone: "nuclear", title: "DROP TABLE?", submitLabel: "Yes", '
            . 'confirmPhrase: "drop production", id: "legacy-nuclear"}) }}';

        $output = $this->twig->createTemplate($template)->render();

        self::assertStringContainsString('fa-modal--danger', $output);
        // Cooldown auto-bumped to at least 5
        self::assertStringContainsString('data-fa-confirm-cooldown-value="5"', $output);
        self::assertStringContainsString('data-fa-confirm-phrase-value="drop production"', $output);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $props
     */
    private function renderConfirm(array $props): string
    {
        $template = '{% import "_components/_fa_modal.html.twig" as _fa_modal %}'
            . '{{ _fa_modal.confirm(props) }}';

        return $this->twig->createTemplate($template)->render(['props' => $props]);
    }

    /**
     * @param array<string, mixed> $props
     */
    private function renderSettings(array $props): string
    {
        $template = '{% import "_components/_fa_modal.html.twig" as _fa_modal %}'
            . '{{ _fa_modal.settings(props) }}';

        return $this->twig->createTemplate($template)->render(['props' => $props]);
    }
}
