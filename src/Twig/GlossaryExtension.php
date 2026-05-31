<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\GlossaryService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the ISMS glossary to templates so a term's definition can be rendered
 * INLINE (no async fetch) into the fa-glossary-tooltip — instant, no API/rate
 * limit, works without JS:
 *
 *   {{ fa_glossary_tooltip(glossary('Bedrohung', app.request.locale)) }}
 */
final class GlossaryExtension extends AbstractExtension
{
    public function __construct(
        private readonly GlossaryService $glossary,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('glossary', $this->glossary(...)),
        ];
    }

    /**
     * Returns a config array ready to spread into the fa-glossary-tooltip macro
     * (acronym, fullText, normRef, definition), or null when the term is unknown.
     *
     * @return array{acronym: string, fullText: string, normRef: ?string, definition: string}|null
     */
    public function glossary(string $acronym, string $locale = 'de'): ?array
    {
        $entry = $this->glossary->lookup($acronym, $locale);
        if ($entry === null) {
            return null;
        }

        return [
            'acronym' => $entry['acronym'],
            'fullText' => $entry['term'],
            'normRef' => $entry['normRef'],
            'definition' => $entry['definition'],
        ];
    }
}
