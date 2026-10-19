<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * _fa_empty_state action `attrs` (restores data-turbo-action="advance" that was
 * lost when management_review switched from an alert link to the macro) and
 * _fa_page_header not emitting an empty badge pill.
 */
final class EmptyStateAndPageHeaderMacroTest extends KernelTestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->twig = self::getContainer()->get('twig');
    }

    #[Test]
    public function emptyStateActionRendersAttrsEscaped(): void
    {
        $html = $this->twig->createTemplate(
            "{% import '_components/_fa_empty_state.html.twig' as e %}"
            . "{{ e.render({ title: 'T', actions: [{ label: 'Go', href: '/x', attrs: { 'data-turbo-action': 'advance', 'data-x': 'a\"b' } }] }) }}"
        )->render();

        self::assertStringContainsString('href="/x"', $html);
        self::assertStringContainsString(' data-turbo-action="advance"', $html);
        self::assertStringContainsString(' data-x="a&quot;b"', $html);
    }

    #[Test]
    public function emptyStateActionWithoutAttrsIsUnchanged(): void
    {
        $html = $this->twig->createTemplate(
            "{% import '_components/_fa_empty_state.html.twig' as e %}"
            . "{{ e.render({ title: 'T', actions: [{ label: 'Go', href: '/x' }] }) }}"
        )->render();

        self::assertMatchesRegularExpression('#<a href="/x"\s+class="fa-cyber-btn fa-cyber-btn--primary">#', $html);
        self::assertStringNotContainsString('data-turbo-action', $html);
    }

    #[Test]
    public function managementReviewEmptyListKeepsTurboAdvance(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../templates/management_review/index.html.twig');
        self::assertStringContainsString("attrs: { 'data-turbo-action': 'advance' }", $src);
    }

    #[Test]
    public function pageHeaderOmitsBadgeWhenLabelAndIconAreEmpty(): void
    {
        foreach (["{ label: '' }", "{ label: '  ', icon: '' }", '{}'] as $badge) {
            self::assertStringNotContainsString('fa-page-header__badge', $this->header($badge), $badge);
        }
    }

    #[Test]
    public function pageHeaderKeepsBadgeWithLabelOrIcon(): void
    {
        $label = $this->header("{ label: 'ISMS' }");
        self::assertStringContainsString('fa-page-header__badge', $label);
        self::assertStringContainsString('ISMS', $label);

        $icon = $this->header("{ icon: 'plus', label: '' }");
        self::assertStringContainsString('fa-page-header__badge', $icon);
        self::assertStringContainsString('fa-icon--plus', $icon);
    }

    private function header(string $badge): string
    {
        return $this->twig->createTemplate(
            "{% import '_components/_fa_page_header.html.twig' as h %}"
            . "{{ h.render({ title: 'Title', badge: {$badge} }) }}"
        )->render();
    }
}
