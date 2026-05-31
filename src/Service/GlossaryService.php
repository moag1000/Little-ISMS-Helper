<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Newcomer-friendly ISMS glossary, backed by config/glossary.yaml.
 *
 * Powers the fa-glossary-tooltip component (lazy-loaded via
 * /api/glossary/{acronym}) and the help page, so a junior ISB hovering an
 * acronym like "SoA" or "Restrisiko" gets a 1–2 sentence explanation with the
 * relevant norm reference instead of bare jargon.
 */
final class GlossaryService
{
    /** @var array<string, array<string, mixed>>|null lazily-parsed, key = normalized acronym */
    private ?array $terms = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/glossary.yaml')]
        private readonly string $glossaryCatalogPath,
    ) {
    }

    /**
     * @return array{acronym: string, term: string, normRef: ?string, definition: string}|null
     */
    public function lookup(string $acronym, string $locale = 'de'): ?array
    {
        $terms = $this->terms();
        $entry = $terms[$this->normalize($acronym)] ?? null;
        if ($entry === null) {
            return null;
        }

        $loc = str_starts_with($locale, 'en') ? 'en' : 'de';
        $term = $entry['term'] ?? [];
        $def = $entry['definition'] ?? [];

        return [
            'acronym' => (string) $entry['__key'],
            'term' => (string) ($term[$loc] ?? $term['de'] ?? $entry['__key']),
            'normRef' => isset($entry['norm_ref']) ? (string) $entry['norm_ref'] : null,
            'definition' => (string) ($def[$loc] ?? $def['de'] ?? ''),
        ];
    }

    /**
     * @return list<array{acronym: string, term: string, normRef: ?string, definition: string}>
     */
    public function all(string $locale = 'de'): array
    {
        $out = [];
        foreach (array_keys($this->terms()) as $key) {
            $entry = $this->lookup($key, $locale);
            if ($entry !== null) {
                $out[] = $entry;
            }
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp($a['acronym'], $b['acronym']));

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function terms(): array
    {
        if ($this->terms !== null) {
            return $this->terms;
        }

        $parsed = [];
        if (is_readable($this->glossaryCatalogPath)) {
            $raw = Yaml::parseFile($this->glossaryCatalogPath);
            $parsed = is_array($raw['terms'] ?? null) ? $raw['terms'] : [];
        }

        $this->terms = [];
        foreach ($parsed as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entry['__key'] = (string) $key;
            $this->terms[$this->normalize((string) $key)] = $entry;
        }

        return $this->terms;
    }

    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
