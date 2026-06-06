<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Regression net for the Alva-hint focus-filter deep-link UI.
 *
 * When a count-hint deep-links to a list narrowed to `?focus=<key>`, the list
 * must visibly tell the user WHAT is filtered (the _focus_filter_notice chip)
 * and offer "show all". This test enforces — without a database, via source
 * inspection, so it runs anywhere — that the wiring can never silently rot:
 *
 *   1. every focus key any rule emits has a label mapping in the partial;
 *   2. every such label resolves in BOTH focus_filter translation files;
 *   3. every index template a focus-rule targets includes the partial;
 *   4. the notice's "show all" link preserves other filters (clears only focus).
 */
final class FocusFilterNoticeWiringTest extends TestCase
{
    private const PROJECT_DIR = __DIR__ . '/../..';
    private const PARTIAL = self::PROJECT_DIR . '/templates/_components/_focus_filter_notice.html.twig';

    /** Index templates that host a focus-filtered list (one per focus-emitting controller). */
    private const INDEX_TEMPLATES = [
        'templates/audit_finding/index.html.twig',
        'templates/incident/index.html.twig',
        'templates/risk/index.html.twig',
        'templates/vulnerability/index.html.twig',
        'templates/document/index.html.twig',
        'templates/bcm/exercise_log/index.html.twig',
        'templates/authority/notification/index.html.twig',
        'templates/authority/hub/index.html.twig',
    ];

    /**
     * @return string[] every distinct focus key emitted by any AlvaHint rule
     */
    private function emittedFocusKeys(): array
    {
        $keys = [];
        $dir = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::PROJECT_DIR . '/src/AlvaHint/Rule', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($dir as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if (preg_match_all("/'focus'\s*=>\s*'([a-z_]+)'/", (string) file_get_contents($file->getPathname()), $m)) {
                foreach ($m[1] as $k) {
                    $keys[$k] = $k;
                }
            }
        }

        return array_values($keys);
    }

    #[Test]
    public function everyEmittedFocusKeyHasAPartialLabel(): void
    {
        $partial = (string) file_get_contents(self::PARTIAL);
        $emitted = $this->emittedFocusKeys();

        self::assertNotEmpty($emitted, 'No focus keys found — rules or grep regex changed.');

        foreach ($emitted as $key) {
            self::assertStringContainsString(
                "focus_filter.focus.{$key}",
                $partial,
                "Focus key '{$key}' is emitted by a rule but has no label mapping in the notice partial.",
            );
        }
    }

    #[Test]
    public function everyFocusLabelResolvesInBothLocales(): void
    {
        $emitted = $this->emittedFocusKeys();

        foreach (['de', 'en'] as $locale) {
            $data = Yaml::parseFile(self::PROJECT_DIR . "/translations/focus_filter.{$locale}.yaml");
            $labels = $data['focus_filter']['focus'] ?? [];
            foreach ($emitted as $key) {
                self::assertArrayHasKey(
                    $key,
                    $labels,
                    "Focus key '{$key}' has no '{$locale}' label in focus_filter.{$locale}.yaml.",
                );
                self::assertNotSame('', trim((string) $labels[$key]));
            }
            // clear + aria chrome must exist too
            self::assertArrayHasKey('clear', $data['focus_filter']['notice'] ?? []);
            self::assertArrayHasKey('aria', $data['focus_filter']['notice'] ?? []);
        }
    }

    #[Test]
    public function everyIndexTemplateIncludesTheNotice(): void
    {
        foreach (self::INDEX_TEMPLATES as $rel) {
            $path = self::PROJECT_DIR . '/' . $rel;
            self::assertFileExists($path);
            self::assertStringContainsString(
                '_components/_focus_filter_notice.html.twig',
                (string) file_get_contents($path),
                "Index template {$rel} no longer includes the focus-filter notice.",
            );
        }
    }

    #[Test]
    public function showAllLinkPreservesOtherFiltersByClearingOnlyFocus(): void
    {
        $partial = (string) file_get_contents(self::PARTIAL);

        // Rebuilds the current route URL from the live query with focus=null
        // (Symfony drops null params) — keeps every other active filter.
        self::assertStringContainsString("app.request.attributes.get('_route')", $partial);
        self::assertStringContainsString('app.request.query.all|merge({ focus: null })', $partial);
    }
}
