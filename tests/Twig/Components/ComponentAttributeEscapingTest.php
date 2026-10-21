<?php

declare(strict_types=1);

namespace App\Tests\Twig\Components;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Markup;

/**
 * Component macros print caller-supplied attribute maps. The attribute NAME
 * is an HTML-attribute context: plain `html` escaping leaves spaces and `=`
 * alone, so a name like `x onmouseover=alert(1)` would add an event handler.
 * Names are escaped with the `html_attr` strategy (#1059). The page-header
 * badge label keeps a caller's safe markup and escapes plain strings — Twig's
 * `trim` returns a Markup for a Markup input, so the `|trim` in the macro does
 * not drop the safe flag (pinned here).
 */
final class ComponentAttributeEscapingTest extends KernelTestCase
{
    private const string HOSTILE_NAME = 'x onmouseover=alert(1) y';

    #[Test]
    public function emptyStateEscapesActionAttributeNames(): void
    {
        $html = $this->render(
            "{% import '_components/_fa_empty_state.html.twig' as e %}{{ e.render({title: 't', actions: [{label: 'Go', href: '/x', attrs: attrs}]}) }}",
            ['attrs' => [self::HOSTILE_NAME => 'v', 'data-turbo-action' => 'advance']],
        );

        self::assertStringNotContainsString(' onmouseover=', $html);
        self::assertStringContainsString('data-turbo-action="advance"', $html, 'ordinary names are unchanged');
    }

    #[Test]
    public function pageHeaderEscapesActionAttributeNames(): void
    {
        $html = $this->render(
            "{% import '_components/_fa_page_header.html.twig' as h %}{{ h.render({title: 't', actions: [{label: 'Go', href: '/x', attrs: attrs}]}) }}",
            ['attrs' => [self::HOSTILE_NAME => 'v', 'data-action' => 'click->x#y']],
        );

        self::assertStringNotContainsString(' onmouseover=', $html);
        self::assertStringContainsString('data-action="click-&gt;x#y"', $html);
    }

    #[Test]
    public function pageHeaderBadgeKeepsSafeMarkupAndEscapesPlainStrings(): void
    {
        $template = "{% import '_components/_fa_page_header.html.twig' as h %}{{ h.render({title: 't', badge: {label: label}}) }}";

        $markup = $this->render($template, ['label' => new Markup(' <strong>ISMS</strong> ', 'UTF-8')]);
        self::assertStringContainsString('<strong>ISMS</strong>', $markup, 'safe markup stays markup');

        $plain = $this->render($template, ['label' => '<strong>ISMS</strong>']);
        self::assertStringNotContainsString('<strong>', $plain);
        self::assertStringContainsString('&lt;strong&gt;ISMS&lt;/strong&gt;', $plain);

        $blank = $this->render($template, ['label' => new Markup('   ', 'UTF-8')]);
        self::assertStringNotContainsString('fa-page-header__badge', $blank, 'a blank label still renders no pill');
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(string $source, array $context): string
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->createTemplate($source)->render($context);
    }
}
