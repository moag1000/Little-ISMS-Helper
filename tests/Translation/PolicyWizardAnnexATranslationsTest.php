<?php

declare(strict_types=1);

namespace App\Tests\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Junior-ISB hand-holding for Step 4 (Risk Classification): every one of
 * the 93 ISO/IEC 27001:2022 Annex A controls must ship a:
 *
 *   - control_title.<A.X.Y> — official short name (always visible)
 *   - control_desc.<A.X.Y>  — Junior-friendly 1-Satz-Klartext (tooltip)
 *
 * in BOTH locales (DE + EN). The stub-fallback in the template
 * (`policy_wizard.step.risk_classification.annex_a.control_desc.stub`) is
 * a Notbremse for unauthored keys — these tests guard against that
 * Notbremse becoming the production string for any of the 93 controls.
 */
final class PolicyWizardAnnexATranslationsTest extends TestCase
{
    private const TRANSLATIONS_DIR = __DIR__ . '/../../translations';
    private const DOMAIN = 'policy_wizard';

    /** @return list<string> All 93 ISO/IEC 27001:2022 Annex A control refs. */
    public static function annexAControls(): array
    {
        $controls = [];

        // A.5 — Organisational controls (37)
        for ($i = 1; $i <= 37; $i++) {
            $controls[] = 'A.5.' . $i;
        }
        // A.6 — People controls (8)
        for ($i = 1; $i <= 8; $i++) {
            $controls[] = 'A.6.' . $i;
        }
        // A.7 — Physical controls (14)
        for ($i = 1; $i <= 14; $i++) {
            $controls[] = 'A.7.' . $i;
        }
        // A.8 — Technological controls (34)
        for ($i = 1; $i <= 34; $i++) {
            $controls[] = 'A.8.' . $i;
        }

        return $controls;
    }

    /** @return list<array{0:string}> */
    public static function annexAControlsProvider(): array
    {
        return array_map(static fn (string $c): array => [$c], self::annexAControls());
    }

    #[Test]
    public function testAll93ControlsExist(): void
    {
        // Sanity check on the provider itself.
        self::assertCount(93, self::annexAControls());
    }

    #[Test]
    #[DataProvider('annexAControlsProvider')]
    public function testAll93ControlsHaveTitleDeAndEn(string $ctrl): void
    {
        foreach (['de', 'en'] as $locale) {
            $tree = $this->loadFlattenedTree(self::DOMAIN, $locale);
            $key = 'policy_wizard.step.risk_classification.annex_a.control_title.' . $ctrl;

            self::assertArrayHasKey(
                $key,
                $tree,
                "Missing control_title key '{$key}' in {$locale}.",
            );

            $value = $tree[$key];
            self::assertIsString($value, "control_title for {$ctrl} ({$locale}) must be a string.");
            self::assertNotSame(
                '',
                trim((string) $value),
                "control_title for {$ctrl} ({$locale}) is empty.",
            );
        }
    }

    #[Test]
    #[DataProvider('annexAControlsProvider')]
    public function testAll93ControlsHaveDescriptionDeAndEn(string $ctrl): void
    {
        foreach (['de', 'en'] as $locale) {
            $tree = $this->loadFlattenedTree(self::DOMAIN, $locale);
            $key = 'policy_wizard.step.risk_classification.annex_a.control_desc.' . $ctrl;

            self::assertArrayHasKey(
                $key,
                $tree,
                "Missing control_desc key '{$key}' in {$locale}.",
            );

            $value = $tree[$key];
            self::assertIsString($value, "control_desc for {$ctrl} ({$locale}) must be a string.");
            self::assertNotSame(
                '',
                trim((string) $value),
                "control_desc for {$ctrl} ({$locale}) is empty.",
            );
        }
    }

    /**
     * The template falls back to the stub when a key is missing. Make sure
     * none of the 93 controls accidentally use the stub fallback wording
     * verbatim — that would mean the real description was never authored.
     */
    #[Test]
    public function testNoStubFallbackUsedAtRuntime(): void
    {
        $offenders = [];

        foreach (['de', 'en'] as $locale) {
            $tree = $this->loadFlattenedTree(self::DOMAIN, $locale);
            $stubKey = 'policy_wizard.step.risk_classification.annex_a.control_desc.stub';
            $stubTemplate = $tree[$stubKey] ?? null;

            self::assertIsString(
                $stubTemplate,
                "Stub fallback key '{$stubKey}' must exist in {$locale}.",
            );

            // Marker substring that uniquely identifies the stub wording
            // — present in both DE and EN stub strings.
            $deMarker = 'Beschreibung folgt im Wizard-Output';
            $enMarker = 'Description will follow in the wizard output';

            foreach (self::annexAControls() as $ctrl) {
                $titleKey = 'policy_wizard.step.risk_classification.annex_a.control_title.' . $ctrl;
                $descKey = 'policy_wizard.step.risk_classification.annex_a.control_desc.' . $ctrl;

                foreach ([$titleKey, $descKey] as $key) {
                    $value = $tree[$key] ?? '';
                    if (!is_string($value)) {
                        continue;
                    }

                    if (str_contains($value, $deMarker) || str_contains($value, $enMarker)) {
                        $offenders[] = [
                            'locale' => $locale,
                            'control' => $ctrl,
                            'key' => $key,
                            'value' => $value,
                        ];
                    }
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Annex-A controls fell back to the stub wording — author the proper translation:\n"
            . json_encode($offenders, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        );
    }

    /** @return array<string, mixed> */
    private function loadFlattenedTree(string $domain, string $locale): array
    {
        $path = sprintf('%s/%s.%s.yaml', self::TRANSLATIONS_DIR, $domain, $locale);

        if (!is_file($path)) {
            self::fail("Translation file not found: {$path}");
        }

        $parsed = Yaml::parseFile($path);

        if (!is_array($parsed)) {
            self::fail("YAML root for {$path} is not an array.");
        }

        return $this->flatten($parsed);
    }

    /**
     * @param array<int|string, mixed> $tree
     * @return array<string, mixed>
     */
    private function flatten(array $tree, string $prefix = ''): array
    {
        $out = [];

        foreach ($tree as $key => $value) {
            $full = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value) && $this->isAssoc($value)) {
                $out += $this->flatten($value, $full);
                continue;
            }

            $out[$full] = $value;
        }

        return $out;
    }

    /** @param array<int|string, mixed> $arr */
    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
