<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * Smoke tests for `_fa_tabs.html.twig` — fa-tabs settings-style tab-group.
 *
 * Tests cover:
 *   - Default render (3 tabs, first is active)
 *   - initialTab by string id → second tab is active
 *   - initialTab by int index → second tab is active
 *   - Tab with errors → .has-error + badge present
 *   - disabled tab → aria-disabled="true", no click action
 */
final class TabsMacroTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    #[Test]
    public function rendersThreeTabsWithFirstActive(): void
    {
        $output = $this->renderMacro([
            'id'   => 'test-tabs',
            'tabs' => [
                ['id' => 'alpha', 'label' => 'Alpha'],
                ['id' => 'beta',  'label' => 'Beta'],
                ['id' => 'gamma', 'label' => 'Gamma'],
            ],
        ]);

        // Tab nav container with ARIA role
        self::assertStringContainsString('role="tablist"', $output);

        // Three nav buttons with correct data-tab-id
        self::assertStringContainsString('data-tab-id="alpha"', $output);
        self::assertStringContainsString('data-tab-id="beta"',  $output);
        self::assertStringContainsString('data-tab-id="gamma"', $output);

        // Three panels with correct ids
        self::assertStringContainsString('role="tabpanel"', $output);
        self::assertStringContainsString('id="test-tabs-panel-alpha"', $output);
        self::assertStringContainsString('id="test-tabs-panel-beta"',  $output);
        self::assertStringContainsString('id="test-tabs-panel-gamma"', $output);

        // First tab is active — aria-selected="true" on alpha nav button
        self::assertMatchesRegularExpression(
            '/data-tab-id="alpha"[^>]*aria-selected="true"|aria-selected="true"[^>]*data-tab-id="alpha"/s',
            $output,
        );
        // Alpha panel has is-active class
        self::assertStringContainsString('id="test-tabs-panel-alpha"', $output);
        self::assertMatchesRegularExpression('/id="test-tabs-panel-alpha"[^>]*class="fa-tabs__panel is-active"/s', $output);

        // Beta tab NOT active — aria-selected="false"
        self::assertMatchesRegularExpression(
            '/data-tab-id="beta"[^>]*aria-selected="false"|aria-selected="false"[^>]*data-tab-id="beta"/s',
            $output,
        );
    }

    #[Test]
    public function initialTabStringIdActivatesCorrectTab(): void
    {
        $output = $this->renderMacro([
            'id'          => 'str-tabs',
            'initialTab'  => 'second',
            'tabs'        => [
                ['id' => 'first',  'label' => 'Erst'],
                ['id' => 'second', 'label' => 'Zweit'],
                ['id' => 'third',  'label' => 'Dritt'],
            ],
        ]);

        // Second tab should be active (aria-selected="true")
        self::assertMatchesRegularExpression(
            '/data-tab-id="second"[^>]*aria-selected="true"|aria-selected="true"[^>]*data-tab-id="second"/s',
            $output,
        );
        // Second panel should have is-active class
        self::assertMatchesRegularExpression('/id="str-tabs-panel-second"[^>]*class="fa-tabs__panel is-active"/s', $output);

        // First tab should NOT be active
        self::assertMatchesRegularExpression(
            '/data-tab-id="first"[^>]*aria-selected="false"|aria-selected="false"[^>]*data-tab-id="first"/s',
            $output,
        );
    }

    #[Test]
    public function initialTabIntIndexActivatesCorrectTab(): void
    {
        $output = $this->renderMacro([
            'id'          => 'int-tabs',
            'initialTab'  => 1,
            'tabs'        => [
                ['id' => 'one', 'label' => 'Eins'],
                ['id' => 'two', 'label' => 'Zwei'],
                ['id' => 'tre', 'label' => 'Drei'],
            ],
        ]);

        // Index 1 = "two" should be active (aria-selected="true")
        self::assertMatchesRegularExpression(
            '/data-tab-id="two"[^>]*aria-selected="true"|aria-selected="true"[^>]*data-tab-id="two"/s',
            $output,
        );
        // Two panel has is-active class
        self::assertMatchesRegularExpression('/id="int-tabs-panel-two"[^>]*class="fa-tabs__panel is-active"/s', $output);

        // One should NOT be active
        self::assertMatchesRegularExpression(
            '/data-tab-id="one"[^>]*aria-selected="false"|aria-selected="false"[^>]*data-tab-id="one"/s',
            $output,
        );
    }

    #[Test]
    public function tabWithErrorsRendersHasErrorClassAndBadge(): void
    {
        $output = $this->renderMacro([
            'id'   => 'err-tabs',
            'tabs' => [
                ['id' => 'ok',    'label' => 'OK'],
                ['id' => 'broken', 'label' => 'Broken', 'errors' => 3],
            ],
        ]);

        // Nav item for broken tab must have has-error class
        // class="fa-tabs__nav-item has-error" — exact class string
        self::assertStringContainsString('class="fa-tabs__nav-item has-error"', $output);

        // Badge present with data-tab-id on the broken tab
        self::assertStringContainsString('class="fa-tabs__nav-badge"', $output);

        // Badge text contains the error count "3"
        self::assertMatchesRegularExpression(
            '/fa-tabs__nav-badge[^>]+data-tab-id="broken"[^>]*>\s*3\s*<\/span>/s',
            $output,
        );
    }

    #[Test]
    public function disabledTabRendersAriaDisabledAndNoClickAction(): void
    {
        $output = $this->renderMacro([
            'id'   => 'dis-tabs',
            'tabs' => [
                ['id' => 'active',   'label' => 'Aktiv'],
                ['id' => 'disabled', 'label' => 'Gesperrt', 'disabled' => true],
            ],
        ]);

        // Disabled tab must have aria-disabled="true" somewhere in its button element
        // Extract the button with data-tab-id="disabled" and check for aria-disabled
        self::assertStringContainsString('aria-disabled="true"', $output);

        // Disabled tab button must have is-disabled class
        self::assertStringContainsString('class="fa-tabs__nav-item is-disabled"', $output);

        // Disabled tab must NOT have data-action click handler
        // Isolate the disabled button tag and check it has no data-action
        preg_match_all('/<button([^>]*)>/s', $output, $matches);
        $foundDisabled = false;
        foreach ($matches[1] as $attrs) {
            if (str_contains($attrs, 'data-tab-id="disabled"')) {
                $foundDisabled = true;
                self::assertStringNotContainsString('data-action', $attrs,
                    'Disabled tab button should not have a data-action attribute');
                break;
            }
        }
        self::assertTrue($foundDisabled, 'Should find the button with data-tab-id="disabled"');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function renderMacro(array $config): string
    {
        $template = '{% import "_components/_fa_tabs.html.twig" as _fa_tabs %}{{ _fa_tabs.render(config) }}';

        return $this->twig->createTemplate($template)->render(['config' => $config]);
    }
}
