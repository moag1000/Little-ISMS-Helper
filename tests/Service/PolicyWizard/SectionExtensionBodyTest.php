<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Command\SeedIsoPolicyTemplatesCommand;
use App\Service\Bsi\GsppReuseMap;
use App\Service\PolicyWizard\DoraExtensionCatalogue;
use App\Service\PolicyWizard\GdprSectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\BcmSectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\BsiSectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\C5SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\GsppSectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\Iso27701SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\Nis2SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\SectionExtensionRegistry;
use App\Service\PolicyWizard\SectionExtension\Soc2SectionCatalogue;
use App\Service\PolicyWizard\SectionExtension\StandardSectionCatalogueInterface;
use App\Service\PolicyWizard\SectionExtension\TisaxSectionCatalogue;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Body extensions of the section catalogues end up in the generated ISO policy
 * ({@see SectionExtensionRegistry::appendBodyExtensions()}), so every key a catalogue
 * names must be translated in DE and EN — otherwise the section is silently dropped.
 */
final class SectionExtensionBodyTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** @return list<StandardSectionCatalogueInterface> */
    private static function bodyCatalogues(): array
    {
        return [
            new BsiSectionCatalogue(),
            new C5SectionCatalogue(),
            new Nis2SectionCatalogue(),
            new TisaxSectionCatalogue(),
            new Soc2SectionCatalogue(),
            new Iso27701SectionCatalogue(),
            new BcmSectionCatalogue(),
            new GsppSectionCatalogue(new GsppReuseMap(self::ROOT)),
        ];
    }

    /** @return iterable<string, array{StandardSectionCatalogueInterface}> */
    public static function catalogues(): iterable
    {
        foreach (self::bodyCatalogues() as $catalogue) {
            yield $catalogue->getStandard() => [$catalogue];
        }
    }

    #[Test]
    #[DataProvider('catalogues')]
    public function every_body_extension_is_translated_in_de_and_en(StandardSectionCatalogueInterface $catalogue): void
    {
        $standard = $catalogue->getStandard();
        $seen = 0;
        foreach (array_column(SeedIsoPolicyTemplatesCommand::TEMPLATES, 'topic') as $topic) {
            foreach ($catalogue->sectionsForTopic($topic) as $section) {
                if ($section->renderMode !== 'body_extension') {
                    continue;
                }
                ++$seen;
                foreach (['de', 'en'] as $lang) {
                    self::assertNotSame(
                        '',
                        self::lookup("policy_{$standard}.{$lang}.yaml", $section->bodyTranslationKey),
                        "{$standard}/{$lang}: {$section->bodyTranslationKey}",
                    );
                }
            }
        }
        self::assertGreaterThan(0, $seen, $standard);
    }

    #[Test]
    public function only_adopted_standards_are_appended(): void
    {
        $registry = new SectionExtensionRegistry([
            ...self::bodyCatalogues(),
            new DoraExtensionCatalogue(),
            new GdprSectionCatalogue(),
        ]);
        $translate = static fn (string $key, string $standard): string => self::lookup("policy_{$standard}.de.yaml", $key) ?: $key;

        $result = $registry->appendBodyExtensions('# Zugriff', 'access_control', ['iso27001', 'gspp', 'dora', 'gdpr'], $translate);

        self::assertSame(['gspp'], $result['applied'], 'DORA has its own renderer, GDPR is a document section');
        self::assertStringStartsWith('# Zugriff', $result['body']);
        self::assertStringContainsString('## Ergänzung Grundschutz++', $result['body']);
        self::assertStringNotContainsString('BSI IT-Grundschutz-Erweiterung', $result['body']);

        $both = $registry->appendBodyExtensions('# Zugriff', 'access_control', ['gspp', 'bsi'], $translate);
        self::assertEqualsCanonicalizing(['gspp', 'bsi'], $both['applied']);
        self::assertStringContainsString('BSI IT-Grundschutz-Erweiterung (ORP.4)', $both['body']);
    }

    #[Test]
    public function untranslated_sections_are_skipped_not_rendered_as_keys(): void
    {
        $registry = new SectionExtensionRegistry([new GsppSectionCatalogue(new GsppReuseMap(self::ROOT))]);

        $result = $registry->appendBodyExtensions('# Body', 'backup', ['gspp'], static fn (string $key): string => $key);

        self::assertSame(['body' => '# Body', 'applied' => []], $result);
    }

    private static function lookup(string $file, string $key): string
    {
        $path = self::ROOT . '/translations/' . $file;
        if (!is_file($path)) {
            return '';
        }
        $node = Yaml::parseFile($path);
        foreach (explode('.', $key) as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return '';
            }
            $node = $node[$segment];
        }

        return is_string($node) ? $node : '';
    }
}
