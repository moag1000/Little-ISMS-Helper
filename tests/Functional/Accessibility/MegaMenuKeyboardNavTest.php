<?php

declare(strict_types=1);

namespace App\Tests\Functional\Accessibility;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 4.1 — Mega-Menu WCAG 2.2 AA Keyboard-Nav structural test.
 *
 * Verifies the template/controller declares the correct ARIA roles and
 * attributes for keyboard navigation compliance without booting the full kernel.
 * This is intentionally a fast unit/structural test — end-to-end keyboard
 * interaction testing happens via the Playwright E2E suite.
 *
 * Checks:
 *   1. role="menubar" present on the <nav> element
 *   2. aria-orientation="vertical" present on the <nav> element
 *   3. All L1 trigger buttons have role="menuitem"
 *   4. All L1 trigger buttons have aria-haspopup="menu" (not just "true")
 *   5. All L1 trigger buttons have aria-expanded="false" initially
 *   6. Badge spans have aria-live="polite" for live-region compliance
 *   7. Badge spans have aria-atomic="true"
 *   8. Badge spans have data-controller="live-badge"
 */
final class MegaMenuKeyboardNavTest extends TestCase
{
    private string $templateContent = '';

    protected function setUp(): void
    {
        $templatePath = __DIR__ . '/../../../templates/_components/_mega_menu.html.twig';
        if (!file_exists($templatePath)) {
            self::markTestSkipped('Mega-menu template not found: ' . $templatePath);
        }
        $this->templateContent = (string) file_get_contents($templatePath);
    }

    #[Test]
    public function navHasMenubarRole(): void
    {
        self::assertStringContainsString(
            'role="menubar"',
            $this->templateContent,
            'Expected role="menubar" on the <nav> element for WAI-ARIA Authoring Practices §3.15'
        );
    }

    #[Test]
    public function navHasVerticalAriaOrientation(): void
    {
        self::assertStringContainsString(
            'aria-orientation="vertical"',
            $this->templateContent,
            'Expected aria-orientation="vertical" on the menubar nav for vertical layout'
        );
    }

    #[Test]
    public function triggerButtonsHaveMenuitemRole(): void
    {
        self::assertStringContainsString(
            'role="menuitem"',
            $this->templateContent,
            'Expected role="menuitem" on at least one trigger button'
        );
    }

    #[Test]
    public function triggerButtonsHaveMenuAriaHaspopup(): void
    {
        self::assertStringContainsString(
            'aria-haspopup="menu"',
            $this->templateContent,
            'Expected aria-haspopup="menu" (not just "true") on trigger buttons'
        );
    }

    #[Test]
    public function triggerButtonsHaveAriaExpandedFalseInitially(): void
    {
        self::assertStringContainsString(
            'aria-expanded="false"',
            $this->templateContent,
            'Expected aria-expanded="false" on trigger buttons in initial state'
        );
    }

    #[Test]
    public function badgeSpansHaveAriaLivePolite(): void
    {
        self::assertStringContainsString(
            'aria-live="polite"',
            $this->templateContent,
            'Expected aria-live="polite" on badge spans for WCAG 2.2 SC 4.1.3 live-region compliance'
        );
    }

    #[Test]
    public function badgeSpansHaveAriaAtomic(): void
    {
        self::assertStringContainsString(
            'aria-atomic="true"',
            $this->templateContent,
            'Expected aria-atomic="true" on badge spans to announce full count value on update'
        );
    }

    #[Test]
    public function badgeSpansHaveLiveBadgeController(): void
    {
        self::assertStringContainsString(
            'data-controller="live-badge"',
            $this->templateContent,
            'Expected data-controller="live-badge" on badge spans for Phase 4.4 live polling'
        );
    }

    #[Test]
    public function badgeSpansHaveSourceValue(): void
    {
        self::assertStringContainsString(
            'data-live-badge-source-value="my_day"',
            $this->templateContent,
            'Expected data-live-badge-source-value="my_day" for Mein-Tag badge'
        );
        self::assertStringContainsString(
            'data-live-badge-source-value="activity"',
            $this->templateContent,
            'Expected data-live-badge-source-value="activity" for Aktivität badge'
        );
    }

    #[Test]
    public function badgeSpansHaveLocaleAwareUrlValue(): void
    {
        self::assertStringContainsString(
            'data-live-badge-url-value=',
            $this->templateContent,
            'Expected data-live-badge-url-value on badge spans for locale-aware endpoint URL'
        );
    }

    #[Test]
    public function megaMenuControllerJsHasPanelKeyboardHandler(): void
    {
        $controllerPath = __DIR__ . '/../../../assets/controllers/mega_menu_controller.js';
        if (!file_exists($controllerPath)) {
            self::markTestSkipped('mega_menu_controller.js not found');
        }

        $js = (string) file_get_contents($controllerPath);
        self::assertStringContainsString(
            'handlePanelKeyboard',
            $js,
            'Expected handlePanelKeyboard method in mega_menu_controller.js for ArrowUp/Down in sub-panel'
        );
        self::assertStringContainsString(
            'ArrowDown',
            $js,
            'Expected ArrowDown handler in mega_menu_controller.js'
        );
        self::assertStringContainsString(
            '_focusFirstFlyoutItem',
            $js,
            'Expected _focusFirstFlyoutItem method to focus first sub-item on ArrowDown'
        );
    }
}
