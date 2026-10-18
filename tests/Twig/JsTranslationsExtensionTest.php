<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\JsTranslationsExtension;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Finder\Finder;

/**
 * window.translations.ui is the JavaScript UI dictionary read by faT().
 * Every key a script asks for must exist in DE and EN — otherwise the user
 * sees the raw key.
 */
final class JsTranslationsExtensionTest extends KernelTestCase
{
    #[Test]
    public function shipsUiJsMessagesWithoutPrefixPerLocale(): void
    {
        $de = $this->extension()->jsUiTranslations('de');
        $en = $this->extension()->jsUiTranslations('en');

        self::assertSame('Abbrechen', $de['cancel'] ?? null);
        self::assertSame('Cancel', $en['cancel'] ?? null);
        self::assertArrayNotHasKey('ui.js.cancel', $de);
        self::assertSame([], array_values(array_filter(array_keys($de), static fn (string $k): bool => str_starts_with($k, 'json_builder'))),
            'only ui.js.* is shipped, not the rest of the ui domain');
    }

    #[Test]
    public function germanAndEnglishDictionariesHaveTheSameKeys(): void
    {
        $de = array_keys($this->extension()->jsUiTranslations('de'));
        $en = array_keys($this->extension()->jsUiTranslations('en'));

        self::assertSame([], array_values(array_diff($en, $de)), 'missing in DE');
        self::assertSame([], array_values(array_diff($de, $en)), 'missing in EN');
    }

    #[Test]
    public function everyFaTKeyUsedInJavaScriptExists(): void
    {
        $dictionary = $this->extension()->jsUiTranslations('en');
        $missing = [];
        $finder = (new Finder())->files()->in(dirname(__DIR__, 2) . '/assets')->name('*.js')->exclude('vendor');
        foreach ($finder as $file) {
            preg_match_all("/\\bfaT\\(\\s*'([a-z0-9_.]+)'/", $file->getContents(), $m);
            foreach ($m[1] as $key) {
                if (!array_key_exists($key, $dictionary)) {
                    $missing[] = $file->getRelativePathname() . ': ' . $key;
                }
            }
        }

        self::assertSame([], array_values(array_unique($missing)));
    }

    #[Test]
    public function layoutPartialRendersJsonSafeDictionary(): void
    {
        self::bootKernel();
        /** @var \Twig\Environment $twig */
        $twig = self::getContainer()->get('twig');
        self::getContainer()->get('translator')->setLocale('de');

        $html = $twig->render('_components/_js_translations.html.twig');

        self::assertMatchesRegularExpression('/window\.translations = (\{.*\});/s', $html);
        preg_match('/window\.translations = (\{.*\});/s', $html, $m);
        $decoded = json_decode($m[1], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('Abbrechen', $decoded['ui']['cancel']);
        self::assertStringNotContainsString('</script><', substr($m[1], 0, -1), 'JSON_HEX_TAG keeps markup inert');
    }

    #[Test]
    public function everyLayoutLoadingTheImportmapShipsTheDictionary(): void
    {
        $root = dirname(__DIR__, 2) . '/templates/';
        foreach (['base.html.twig', 'base_auth.html.twig', 'base_public.html.twig', 'setup/_layout.html.twig'] as $layout) {
            $source = (string) file_get_contents($root . $layout);
            self::assertStringContainsString("{% include '_components/_js_translations.html.twig' %}", $source, $layout);
        }
    }

    private function extension(): JsTranslationsExtension
    {
        self::bootKernel();

        return new JsTranslationsExtension(self::getContainer()->get('translator'));
    }
}
