<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\OutputEscapingExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;
use Twig\Markup;

/**
 * S10 (Gate 91): components print record text escaped; HTML reaches a slot only as Markup.
 *
 * Renders the real components with a record value that carries a form, a link and a
 * Stimulus action — the stored-XSS payload of the fifth assessment — and checks that
 * none of it survives as markup, while a capture still renders as HTML.
 */
final class OutputEscapingTest extends KernelTestCase
{
    private const string PAYLOAD = '<form action="/x"><a data-action="click->x#y" href="/evil">Evil</a></form>';

    private function twig(): Environment
    {
        self::bootKernel();
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        return $twig;
    }

    private function render(string $source, array $context = []): string
    {
        return $this->twig()->createTemplate($source)->render(['rec' => self::PAYLOAD] + $context);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function textSlots(): iterable
    {
        yield 'fa_alert body' => ["{% import '_components/_fa_alert.html.twig' as c %}{{ c.render({ body: rec }) }}"];
        yield 'fa_alert embed' => ["{% embed '_components/_fa_alert.html.twig' with { props: { body: rec } } %}{% endembed %}"];
        yield 'alert message' => ["{% import '_components/_alert.html.twig' as c %}{{ c.render(rec, 'warning') }}"];
        yield 'fa_modal message + sub' => ["{% import '_components/_fa_modal.html.twig' as c %}{{ c.confirm({ id: 'm', title: 'T', message: rec, sub: rec }) }}"];
        yield 'fa_table header + cells' => ["{% import '_components/_fa_table.html.twig' as c %}{{ c.render({ headers: [{ label: rec }], rows: [[rec], { cells: [{ value: rec }] }] }) }}"];
        yield 'fa_section body' => ["{% import '_components/_fa_section.html.twig' as c %}{{ c.render({ title: 'T', body: rec, footer: rec, tools: rec }) }}"];
        yield 'fa_toast message' => ["{% import '_components/_fa_toast.html.twig' as c %}{{ c.render({ title: rec, message: rec }) }}"];
        yield 'detail_group value' => ["{% include '_components/_detail_group.html.twig' with { title: 'T', items: [{ label: 'L', value: rec }] } %}"];
    }

    #[Test]
    #[DataProvider('textSlots')]
    public function recordTextInAComponentSlotIsEscaped(string $source): void
    {
        $html = $this->render($source);

        self::assertStringNotContainsString('<form action="/x">', $html);
        self::assertStringNotContainsString('data-action="click->x#y"', $html);
        self::assertStringContainsString('&lt;form', $html);
    }

    #[Test]
    public function aCaptureStillRendersAsHtmlAndEscapesTheRecordInside(): void
    {
        $html = $this->render("{% import '_components/_fa_alert.html.twig' as c %}"
            . '{% set b %}<strong>{{ rec }}</strong>{% endset %}{{ c.render({ body: b }) }}');

        self::assertStringContainsString('<strong>&lt;form', $html);
    }

    #[Test]
    public function translatorHtmlPassesWithFaHtml(): void
    {
        $html = $this->render("{% import '_components/_fa_alert.html.twig' as c %}{{ c.render({ body: 'Hi <em>there</em>'|fa_html }) }}");

        self::assertStringContainsString('Hi <em>there</em>', $html);
    }

    #[Test]
    public function listPageCardAttributesAreEscaped(): void
    {
        $source = '<section{% for _name, _value in card_attrs %} {{ _name|e(\'html_attr\') }}="{{ _value }}"{% endfor %}>';
        $html = $this->render($source, ['card_attrs' => ['data-controller' => 'bulk-actions', 'data-x' => '"><script>x</script>']]);

        self::assertStringContainsString('data-controller="bulk-actions"', $html);
        self::assertStringNotContainsString('<script>', $html);
    }

    #[Test]
    public function faJsonCannotCloseAScriptOrAnAttribute(): void
    {
        $json = (string) (new OutputEscapingExtension())->json(['t' => "</script><b>'\"&"]);

        self::assertStringNotContainsString('</script>', $json);
        self::assertStringNotContainsString("'", $json);
        self::assertStringNotContainsString('"<', $json);
        self::assertSame(['t' => "</script><b>'\"&"], json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function faHtmlKeepsMarkupAndWrapsStrings(): void
    {
        $ext = new OutputEscapingExtension();
        $markup = new Markup('<b>x</b>', 'UTF-8');

        self::assertSame($markup, $ext->html($markup));
        self::assertSame('<i>y</i>', (string) $ext->html('<i>y</i>'));
        self::assertSame('', (string) $ext->html(null));
    }
}
