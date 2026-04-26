<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\Yaml\Yaml;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Liefert MRIS-Hilfetexte aus fixtures/mris/help-texts.yaml als Twig-Funktion
 * fuer Tooltip-Integration in Templates.
 *
 * Nutzung in Twig:
 *   {{ mris_help('mris.help.standfest', 'tooltip', 'de') }}
 *
 * Cached die YAML-Daten in einer statischen Variable um Datei-IO zu minimieren.
 *
 * Quelle: Peddi, R. (2026). MRIS v1.5. CC BY 4.0.
 */
final class MrisHelpTextExtension extends AbstractExtension
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly string $projectDir,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('mris_help', $this->getHelpText(...)),
        ];
    }

    /**
     * Liefert eine Hilfstext-Variante (tooltip|inline_help|glossar.definition)
     * fuer einen Hilfe-Key in der gewuenschten Sprache.
     *
     * Liefert leeren String wenn Key oder Variante nicht existiert — sicher
     * fuer Template-Embeds (kein Exception, kein Layout-Bruch).
     */
    private function getHelpText(string $key, string $variant = 'tooltip', ?string $locale = null): string
    {
        $items = $this->loadItems();
        if (!isset($items[$key])) {
            return '';
        }
        $entry = $items[$key];
        $locale ??= 'de';

        return match ($variant) {
            'tooltip' => $entry['tooltip'][$locale] ?? '',
            'inline_help', 'inline' => $entry['inline_help'][$locale] ?? '',
            'glossar', 'glossary' => $entry['glossar']['definition_' . $locale] ?? '',
            default => '',
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function loadItems(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $path = $this->projectDir . '/fixtures/mris/help-texts.yaml';
        if (!is_file($path)) {
            return $this->cache = [];
        }

        try {
            $payload = Yaml::parseFile($path);
        } catch (\Throwable) {
            return $this->cache = [];
        }

        $items = [];
        foreach ($payload['items'] ?? [] as $item) {
            $key = $item['key'] ?? null;
            if (is_string($key)) {
                $items[$key] = $item;
            }
        }
        return $this->cache = $items;
    }
}
