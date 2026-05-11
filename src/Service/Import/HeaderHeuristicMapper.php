<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * Suggests entity-field mappings for spreadsheet column headers using
 * string-normalisation + Levenshtein-distance heuristics.
 *
 * Supported entity types: asset, supplier, control
 *
 * Usage:
 *   $suggestions = $mapper->suggestMappings(['Name', 'Typ', 'Verantwortlich'], 'asset');
 *   // => ['Name' => ['target' => 'name', 'confidence' => 1.0], ...]
 */
final class HeaderHeuristicMapper
{
    /**
     * Minimum confidence score to include a suggestion.
     * Mappings below this threshold are omitted (user must pick manually).
     */
    public const MIN_CONFIDENCE = 0.6;

    /**
     * Alias tables per entity type.
     *
     * Structure: 'entityType' => ['alias' => 'entityField', ...]
     *
     * Aliases are lowercased, special-char-free variants of known column headers
     * in German and English.
     *
     * @var array<string, array<string, string>>
     */
    private const ALIASES = [
        'asset' => [
            // name
            'name'          => 'name',
            'bezeichnung'   => 'name',
            'titel'         => 'name',
            'title'         => 'name',
            // assetType
            'type'          => 'assetType',
            'typ'           => 'assetType',
            'art'           => 'assetType',
            'assettype'     => 'assetType',
            // owner
            'owner'         => 'owner',
            'verantwortlich' => 'owner',
            'besitzer'      => 'owner',
            'responsible'   => 'owner',
            // classification
            'classification'   => 'classification',
            'klassifizierung'  => 'classification',
            'klassifikation'   => 'classification',
            // confidentiality
            'confidentiality'  => 'confidentiality',
            'vertraulichkeit'  => 'confidentiality',
            'c'                => 'confidentiality',
            // integrity
            'integrity'        => 'integrity',
            'integritaet'      => 'integrity',
            'integritat'       => 'integrity',
            'i'                => 'integrity',
            // availability
            'availability'     => 'availability',
            'verfuegbarkeit'   => 'availability',
            'verfugbarkeit'    => 'availability',
            'a'                => 'availability',
        ],
        'supplier' => [
            // name
            'name'           => 'name',
            'firma'          => 'name',
            'lieferant'      => 'name',
            'supplier'       => 'name',
            'vendor'         => 'name',
            // contactEmail
            'contactemail'   => 'contactEmail',
            'email'          => 'contactEmail',
            'kontakt'        => 'contactEmail',
            'kontaktemail'   => 'contactEmail',
            'mail'           => 'contactEmail',
            // criticality
            'criticality'    => 'criticality',
            'kritikalitaet'  => 'criticality',
            'kritikalitat'   => 'criticality',
            'kritisch'       => 'criticality',
            // isDoraRelevant
            'dorarelevant'   => 'isDoraRelevant',
            'dora'           => 'isDoraRelevant',
            'dora_relevant'  => 'isDoraRelevant',
            'isadorarelevant' => 'isDoraRelevant',
        ],
        'control' => [
            // identifier
            'identifier'     => 'identifier',
            'ref'            => 'identifier',
            'annex'          => 'identifier',
            'controlid'      => 'identifier',
            'control_id'     => 'identifier',
            'id'             => 'identifier',
            // title
            'title'          => 'title',
            'titel'          => 'title',
            'name'           => 'title',
            'bezeichnung'    => 'title',
            // applicability
            'applicability'  => 'applicability',
            'applicable'     => 'applicability',
            'anwendbar'      => 'applicability',
            'anwendbarkeit'  => 'applicability',
            // justification
            'justification'  => 'justification',
            'begruendung'    => 'justification',
            'begrundung'     => 'justification',
            'reason'         => 'justification',
            'begruendung'    => 'justification',
        ],
    ];

    /**
     * Suggest field mappings for a set of spreadsheet column headers.
     *
     * Returns a map of sourceColumn => ['target' => entityField, 'confidence' => float].
     * Columns whose best match falls below MIN_CONFIDENCE are omitted.
     *
     * @param string[] $headers    Column headers as they appear in the spreadsheet
     * @param string   $entityType One of 'asset', 'supplier', 'control'
     *
     * @return array<string, array{target: string, confidence: float}>
     */
    public function suggestMappings(array $headers, string $entityType): array
    {
        $aliases = self::ALIASES[$entityType] ?? [];

        if ($aliases === []) {
            return [];
        }

        $suggestions = [];

        foreach ($headers as $header) {
            $normalised = $this->normalise($header);

            if ($normalised === '') {
                continue;
            }

            [$target, $confidence] = $this->bestMatch($normalised, $aliases);

            if ($confidence >= self::MIN_CONFIDENCE) {
                $suggestions[$header] = [
                    'target'     => $target,
                    'confidence' => round($confidence, 4),
                ];
            }
        }

        return $suggestions;
    }

    /**
     * Normalise a header string: lowercase + remove non-alphanumeric characters.
     * Umlauts are transliterated to their ASCII equivalents before removal.
     */
    private function normalise(string $header): string
    {
        // Umlaut transliteration (German-specific)
        $header = str_replace(
            ['ä', 'ö', 'ü', 'Ä', 'Ö', 'Ü', 'ß'],
            ['ae', 'oe', 'ue', 'ae', 'oe', 'ue', 'ss'],
            $header,
        );

        // Lowercase and strip everything except a-z and 0-9
        return preg_replace('/[^a-z0-9]/', '', strtolower($header)) ?? '';
    }

    /**
     * Find the alias with the highest Levenshtein-based confidence for a normalised header.
     *
     * Confidence formula:
     *   1.0 - (levenshtein / max(len(normalised), len(alias)))
     *
     * Exact match → 1.0; completely different → approaches 0.0.
     *
     * @param array<string, string> $aliases
     *
     * @return array{string, float}  [$entityField, $confidence]
     */
    private function bestMatch(string $normalised, array $aliases): array
    {
        $bestField      = '';
        $bestConfidence = 0.0;

        foreach ($aliases as $alias => $field) {
            // Exact match short-circuit
            if ($normalised === $alias) {
                return [$field, 1.0];
            }

            $distance   = levenshtein($normalised, $alias);
            $maxLen     = max(strlen($normalised), strlen($alias));
            $confidence = $maxLen > 0 ? 1.0 - ($distance / $maxLen) : 0.0;

            if ($confidence > $bestConfidence) {
                $bestConfidence = $confidence;
                $bestField      = $field;
            }
        }

        return [$bestField, $bestConfidence];
    }
}
