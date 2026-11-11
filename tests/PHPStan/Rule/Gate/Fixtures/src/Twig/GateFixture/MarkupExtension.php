<?php

declare(strict_types=1);

namespace App\Twig\GateFixture;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;
use Twig\{Environment, Markup as Html};
use Twig\Markup;
use Twig\TwigFilter;

/** Gate 92 fixture (PHP part): every `expect:` line is a mutation the rule must report. */
final class MarkupExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('a', $this->a(...), ['is_safe' => ['html']]), // expect: gate92.isSafe
            new TwigFilter('b', $this->a(...), [
                'is_safe' // expect: gate92.isSafe
                    => ['html'],
            ]),
            new TwigFilter('c', $this->a(...), ['is_safe_callback' => $this->a(...)]), // expect: gate92.isSafe
            // @markup-safe: escapes every record value with htmlspecialchars before joining
            new TwigFilter('d', $this->a(...), ['is_safe' => ['html']]),
            new TwigFilter('e', $this->a(...), ['is_safe' => []]),
        ];
    }

    public function a(string $x): Markup
    {
        return new Markup($x, 'UTF-8'); // expect: gate92.markup
    }

    public function groupUseAlias(string $x, Environment $twig): Html
    {
        return new Html($x, $twig->getCharset()); // expect: gate92.markup
    }

    public function lineBreakAfterNew(string $x): Markup
    {
        return new // expect: gate92.markup
            \Twig\Markup($x, 'UTF-8');
    }

    public function reviewed(string $x): Markup
    {
        // @markup-safe: $x went through htmlspecialchars() above
        return new Markup(htmlspecialchars($x), 'UTF-8');
    }

    public function markerWithoutReason(string $x): Markup
    {
        // @markup-safe:
        return new Markup($x, 'UTF-8'); // expect: gate92.markup
    }

    public function markerInAString(string $x): Markup
    {
        $note = '@markup-safe: not a comment';

        return new Markup($x . $note, 'UTF-8'); // expect: gate92.markup
    }

    public function classAsValue(string $x): object
    {
        $c = Markup::class; // expect: gate92.markupClass

        return new $c($x, 'UTF-8');
    }

    /** T4 K3: the marker of the first Markup does not cover a second one below. */
    public function secondBelowAMarkedOne(string $x, string $title): array
    {
        // @markup-safe: $x went through htmlspecialchars() above
        $a = new Markup(htmlspecialchars($x), 'UTF-8');
        $b = new Markup($title, 'UTF-8'); // expect: gate92.markup

        return [$a, $b];
    }

    /** T4 K2: the class by its name as a string. */
    public function classAsString(string $x): object
    {
        $c = 'Twig\\Markup'; // expect: gate92.markupClass

        return new $c($x, 'UTF-8');
    }

    /** T4 K1: a class declared safe for the escaper. */
    public function safeClasses(\Twig\Environment $env): void
    {
        $runtime = $env->getRuntime(\Twig\Runtime\EscaperRuntime::class);
        $runtime->setSafeClasses([\stdClass::class => ['html']]); // expect: gate92.safeClass
        $runtime->addSafeClass(\stdClass::class, ['html']); // expect: gate92.safeClass
    }

    public function word(): PhpWord
    {
        Settings::setOutputEscapingEnabled(false); // expect: gate92.escapingOff
        new TemplateProcessor('x.docx'); // expect: gate92.templateProcessor

        return new PhpWord(); // expect: gate92.phpWord
    }
}
