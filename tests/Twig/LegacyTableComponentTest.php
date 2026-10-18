<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

/**
 * The legacy `_components/_table.html.twig` embed (80+ call sites) must render
 * the Aurora fa-table markup, translating the Bootstrap-era parameters the
 * call sites still pass, and never fall back to a Bootstrap `.table`.
 */
final class LegacyTableComponentTest extends KernelTestCase
{
    #[Test]
    public function defaultEmbedRendersFaTableMarkup(): void
    {
        $html = $this->render('{}');

        self::assertStringContainsString('<div class="fa-table-wrap" data-controller="row-click"', $html);
        self::assertStringContainsString('<table class="fa-table fa-table--plain fa-table--hover">', $html);
        self::assertStringContainsString('<thead class="fa-table__head">', $html);
        self::assertStringContainsString('<tbody class="fa-table__body">', $html);
        self::assertDoesNotMatchRegularExpression('/class="(?:[^"]*\s)?(table|table-responsive)(?:\s[^"]*)?"/', $html);
    }

    #[Test]
    public function bootstrapClassTokensMapToFaTableModifiers(): void
    {
        $html = $this->render("{'class': 'table-sm table-bordered table-striped mb-0 table-bulk-selectable', 'variant': 'striped'}");

        self::assertStringContainsString(
            '<table class="fa-table fa-table--plain fa-table--hover fa-table--striped fa-table--compact fa-table--bordered table-bulk-selectable">',
            $html,
        );
    }

    #[Test]
    public function stickyTheadMarkersBecomeTheStickyHeadModifier(): void
    {
        $html = $this->render("{'theadClass': 'table-light thead-sticky bg-alt', 'wrapperClass': 'table-container', 'rowClick': false}");

        self::assertStringContainsString('<div class="fa-table-wrap table-container">', $html);
        self::assertStringContainsString('fa-table--sticky-head', $html);
        self::assertStringContainsString('<thead class="fa-table__head bg-alt">', $html);
        self::assertStringNotContainsString('table-light', $html);
    }

    #[Test]
    public function nonResponsiveWithoutRowClickRendersNoWrapper(): void
    {
        $html = $this->render("{'responsive': false, 'rowClick': false}");

        self::assertStringStartsWith('<table class="fa-table', trim($html));
    }

    private function render(string $with): string
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $source = "{% embed '_components/_table.html.twig' with " . $with . " %}"
            . '{% block table_head %}<tr><th>H</th></tr>{% endblock %}'
            . '{% block table_body %}<tr><td>C</td></tr>{% endblock %}'
            . '{% endembed %}';

        return $twig->createTemplate($source)->render([]);
    }
}
