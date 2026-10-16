<?php

declare(strict_types=1);

namespace App\Tests\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The Aurora macros (templates/_components/_fa_*.html.twig) render on every
 * page in both locales, so their own texts — default labels, aria-labels,
 * legends, empty states — must come from the translator (ui.component.*),
 * never be hard-coded German or English.
 */
final class AuroraMacroTextTest extends KernelTestCase
{
    /** German words that used to sit literally in the macros. */
    private const GERMAN = '/[äöüÄÖÜß]|\b(Schritt|Zurück|Weiter|Abbrechen|Speichern|Schließen|Bestätigen|Keine|Alle|anzeigen|Ausblenden|Später|Fehler|Modul|Stand|Lieferanten|Risiken|Tippe)\b/u';

    #[Test]
    public function macrosContainNoHardCodedGermanText(): void
    {
        $hits = [];
        $finder = (new Finder())->files()->in(dirname(__DIR__, 2) . '/templates/_components')->name('_fa_*.html.twig');
        foreach ($finder as $file) {
            // Comments and Twig tags/expressions may mention German (docs, keys).
            $markup = (string) preg_replace(['/\{#.*?#\}/s', '/\{%.*?%\}/s', '/\{\{.*?\}\}/s'], '', $file->getContents());
            foreach (explode("\n", $markup) as $no => $line) {
                if (preg_match(self::GERMAN, $line) === 1) {
                    $hits[] = $file->getFilename() . ':' . ($no + 1) . ': ' . trim($line);
                }
            }
        }

        self::assertSame([], $hits);
    }

    /**
     * Twig comments do not nest: an inner `{# … #}` in a usage example ends the
     * doc comment early and turns the rest of it into live template body.
     */
    #[Test]
    public function componentDocCommentsDoNotNest(): void
    {
        $nested = [];
        $finder = (new Finder())->files()->in(dirname(__DIR__, 2) . '/templates/_components')->name('*.twig');
        foreach ($finder as $file) {
            preg_match_all('/\{#(.*?)#\}/s', $file->getContents(), $m);
            foreach ($m[1] as $comment) {
                if (str_contains($comment, '{#')) {
                    $nested[] = $file->getFilename();
                }
            }
        }

        self::assertSame([], $nested);
    }

    #[Test]
    public function everyUiComponentKeyExistsInGermanAndEnglish(): void
    {
        self::bootKernel();
        $translator = self::getContainer()->get(TranslatorInterface::class);
        $missing = [];
        $finder = (new Finder())->files()->in(dirname(__DIR__, 2) . '/templates/_components')->name('_fa_*.html.twig');
        foreach ($finder as $file) {
            preg_match_all("/'(ui\\.component\\.[a-z0-9_.]+)'/", $file->getContents(), $m);
            foreach (array_unique($m[1]) as $key) {
                if (str_ends_with($key, '.')) {
                    continue; // dynamic prefix, covered by the rendering cases below
                }
                foreach (['de', 'en'] as $locale) {
                    if (!$translator->getCatalogue($locale)->defines($key, 'ui')) {
                        $missing[] = "$locale: $key ({$file->getFilename()})";
                    }
                }
            }
        }

        self::assertSame([], $missing);
    }

    /** @return iterable<string, array{string, string, list<string>}> */
    public static function renderedMacros(): iterable
    {
        yield 'coverage matrix empty state' => [
            "{% import '_components/_fa_coverage_matrix.html.twig' as m %}{{ m.fa_coverage_matrix({frameworks: [], snapshotAt: '2026-10-01'}) }}",
            'en',
            ['Framework coverage matrix', 'As of: 2026-10-01', 'Maturity', 'No frameworks active'],
        ];
        yield 'density toggle' => [
            "{% import '_components/_fa_density_toggle.html.twig' as m %}{{ m.fa_density_toggle({formAction: '/x', lockedBy: 'Admin'}) }}",
            'en',
            ['Choose menu density', 'Expert', 'All items · maturity scores', 'Locked by: Admin'],
        ];
        yield 'stepper aria labels (de)' => [
            "{% import '_components/_fa_stepper.html.twig' as m %}{{ m.render([{label: 'A'}, {label: 'B'}], 1) }}",
            'de',
            ['Schritt 1: A – abgeschlossen', 'aria-label="Assistent"'],
        ];
        yield 'matrix legend' => [
            "{% import '_components/_fa_matrix_table.html.twig' as m %}{{ m.render({rows: [], cols: [], cells: {}, legend: true}) }}",
            'de',
            ['Schweregrad-Legende', 'Kritisch (&gt;16)'],
        ];
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('renderedMacros')]
    public function macroTextsFollowTheLocale(string $source, string $locale, array $expected): void
    {
        self::bootKernel();
        self::getContainer()->get('translator')->setLocale($locale);
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->createTemplate($source)->render([]);

        foreach ($expected as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringNotContainsString('ui.component.', $html, 'untranslated key');
    }
}
