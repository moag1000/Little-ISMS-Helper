<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;

/**
 * Smoke tests for Aurora-v4 table-macro extensions introduced by Bucket 3 of
 * the TODO remediation plan (May 2026):
 *
 *   - _fa_matrix_table  · mode='rag' (compliance-heatmap variant)
 *   - _fa_settings_table · kind='widget' + headers/trailing (column-mapping)
 *   - _fa_action_bar     · per-action disabled + attrs + button tag
 *
 * Each test renders the macro with a representative invocation and asserts
 * key markup contracts (CSS classes, ARIA attributes, slots).
 */
final class AuroraTableMacrosBucket3Test extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');

        // Worktree templates MUST come first so the test exercises the
        // bucket-3 versions rather than any pre-existing baseline in the
        // main-repo templates directory that the kernel is wired to.
        // We use the kernel Twig (keeps trans/asset/symfony filters working)
        // and prepend a fresh FilesystemLoader pointing at the worktree.
        // Twig file-modification-time checks ensure we re-compile when the
        // worktree macro changed.
        $worktreeTemplates = dirname(__DIR__, 2) . '/templates';
        $currentLoader = $this->twig->getLoader();
        $worktreeLoader = new FilesystemLoader([$worktreeTemplates]);
        $chain = new ChainLoader([$worktreeLoader, $currentLoader]);
        $this->twig->setLoader($chain);

        // Disable Twig cache for this test class so worktree edits are
        // always recompiled (the kernel cache may hold compiled versions
        // from previous boot runs that point at the main-repo macro file).
        $cache = $this->twig->getCache(false);
        if ($cache) {
            // Switch to null-cache so {{ render() }} always re-parses source.
            $this->twig->setCache(false);
        }
    }

    // ── fa-matrix-table RAG mode ───────────────────────────────────────────

    #[Test]
    public function matrixTableRagModeAppliesRagModifierClass(): void
    {
        $output = $this->renderMatrixRag();

        self::assertStringContainsString('fa-matrix-table--rag', $output);
        self::assertStringContainsString('fa-matrix-table isms-risk-matrix', $output);
    }

    #[Test]
    public function matrixTableRagModeMapsGreenStatusToLowSeverity(): void
    {
        $output = $this->renderMatrixRag();

        // Green → low severity → fa-matrix-table__cell--low
        self::assertStringContainsString('fa-matrix-table__cell--low', $output);
    }

    #[Test]
    public function matrixTableRagModeMapsAmberStatusToMediumSeverity(): void
    {
        $output = $this->renderMatrixRag();

        self::assertStringContainsString('fa-matrix-table__cell--medium', $output);
    }

    #[Test]
    public function matrixTableRagModeMapsRedStatusToCriticalSeverity(): void
    {
        $output = $this->renderMatrixRag();

        self::assertStringContainsString('fa-matrix-table__cell--critical', $output);
    }

    #[Test]
    public function matrixTableRagModeRendersPercentageRate(): void
    {
        $output = $this->renderMatrixRag();

        // 92% → green cell
        self::assertStringContainsString('92%', $output);
        self::assertStringContainsString('fa-matrix-table__rate', $output);
    }

    #[Test]
    public function matrixTableRagModeRendersCaptionAndLegend(): void
    {
        $output = $this->renderMatrixRag();

        self::assertStringContainsString('fa-matrix-table__caption', $output);
        self::assertStringContainsString('Framework × Cluster', $output);
        // Legend in RAG-mode shows Green/Amber/Red
        self::assertStringContainsString('Green (≥80%)', $output);
        self::assertStringContainsString('Red (&lt;40%)', $output);
    }

    #[Test]
    public function matrixTableRagModeHasRowAndColumnHeaders(): void
    {
        $output = $this->renderMatrixRag();

        self::assertStringContainsString('role="columnheader"', $output);
        self::assertStringContainsString('role="rowheader"', $output);
    }

    #[Test]
    public function matrixTableSeverityModeStillRendersCount(): void
    {
        $output = $this->twig->createTemplate(
            "{% import '_components/_fa_matrix_table.html.twig' as m %}"
            . "{{ m.render({ title: 'X', rows: ['R'], cols: ['C'], cells: [[ { score: 12, count: 3 } ]] }) }}"
        )->render([]);

        self::assertStringContainsString('fa-matrix-table__count', $output);
        self::assertStringContainsString('>3<', $output);
        // Score 12 → high
        self::assertStringContainsString('fa-matrix-table__cell--high', $output);
    }

    // ── fa-settings-table widget kind ─────────────────────────────────────

    #[Test]
    public function settingsTableWidgetKindPassesThroughRawHtml(): void
    {
        $output = $this->twig->createTemplate(
            "{% import '_components/_fa_settings_table.html.twig' as s %}"
            . "{{ s.render({ rows: [ { kind: 'widget', label: 'Header A', name: 'col_0', "
            . "widget: '<select name=\"col_0\"><option>X</option></select>' } ] }) }}"
        )->render([]);

        self::assertStringContainsString('<select name="col_0">', $output);
        self::assertStringContainsString('Header A', $output);
    }

    #[Test]
    public function settingsTableHeadersPropRendersThead(): void
    {
        $output = $this->twig->createTemplate(
            "{% import '_components/_fa_settings_table.html.twig' as s %}"
            . "{{ s.render({ headers: [{label: 'Source'},{label: 'Target'},{label: 'Confidence'}], "
            . "rows: [ { kind: 'widget', label: 'name', name: 'col_0', "
            . "widget: '<select></select>', trailing: '<span>chip</span>' } ] }) }}"
        )->render([]);

        self::assertStringContainsString('fa-settings-table__head', $output);
        self::assertStringContainsString('Source', $output);
        self::assertStringContainsString('Target', $output);
        self::assertStringContainsString('Confidence', $output);
        // Trailing cell renders the chip HTML
        self::assertStringContainsString('fa-settings-table__trailing-cell', $output);
        self::assertStringContainsString('<span>chip</span>', $output);
    }

    // ── fa-action-bar disabled + attrs + button tag ────────────────────────

    #[Test]
    public function actionBarDisabledActionRendersAriaDisabledSpan(): void
    {
        $output = $this->twig->createTemplate(
            "{% import '_components/_fa_action_bar.html.twig' as a %}"
            . "{{ a.render({ secondary: [ { label: 'Export', href: '/x', disabled: true } ] }) }}"
        )->render([]);

        self::assertStringContainsString('aria-disabled="true"', $output);
        self::assertStringContainsString('is-disabled', $output);
        self::assertStringContainsString('tabindex="-1"', $output);
        self::assertStringContainsString('role="button"', $output);
        // Should NOT be rendered as a clickable <a href>
        self::assertStringNotContainsString('<a href="/x"', $output);
    }

    #[Test]
    public function actionBarAttrsPropEmitsDataAttributes(): void
    {
        $output = $this->twig->createTemplate(
            "{% import '_components/_fa_action_bar.html.twig' as a %}"
            . "{{ a.render({ secondary: [ { label: 'Export', href: '/x', attrs: { 'data-turbo': 'false' } } ] }) }}"
        )->render([]);

        self::assertStringContainsString('data-turbo="false"', $output);
    }

    #[Test]
    public function actionBarButtonTagRendersButtonElement(): void
    {
        $output = $this->twig->createTemplate(
            "{% import '_components/_fa_action_bar.html.twig' as a %}"
            . "{{ a.render({ primary: { label: 'Save', tag: 'button', id: 'saveBtn', "
            . "variant: 'primary', attrs: { 'data-foo': 'bar' } } }) }}"
        )->render([]);

        self::assertStringContainsString('<button type="button"', $output);
        self::assertStringContainsString('id="saveBtn"', $output);
        self::assertStringContainsString('data-foo="bar"', $output);
        self::assertStringContainsString('fa-cyber-btn--primary', $output);
    }

    #[Test]
    public function actionBarControllerPropEmitsDataController(): void
    {
        $output = $this->twig->createTemplate(
            "{% import '_components/_fa_action_bar.html.twig' as a %}"
            . "{{ a.render({ controller: 'compliance-compare', wrapperAttrs: { 'data-x': '1' } }) }}"
        )->render([]);

        self::assertStringContainsString('data-controller="compliance-compare"', $output);
        self::assertStringContainsString('data-x="1"', $output);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function renderMatrixRag(): string
    {
        return $this->twig->createTemplate(
            "{% import '_components/_fa_matrix_table.html.twig' as m %}"
            . "{{ m.render({"
            . "  mode: 'rag',"
            . "  title: 'Framework × Cluster',"
            . "  caption: 'Caption hidden',"
            . "  rows: ['ISO 27001'],"
            . "  cols: ['Cluster A', 'Cluster B', 'Cluster C'],"
            . "  cells: [[ { status: 'green', rate: 0.92 }, { status: 'amber', rate: 0.55 }, { status: 'red', rate: 0.15 } ]],"
            . "  legend: true"
            . "}) }}"
        )->render([]);
    }
}
