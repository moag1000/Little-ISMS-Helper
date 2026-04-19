<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Repository\ComplianceRequirementRepository;

/**
 * Auto-Mapping Suggestion Service (A2).
 *
 * Scores every active ComplianceRequirement against a given Control
 * by Jaccard token-overlap and returns the top-N suggestions per
 * framework. The CM uses these as one-click-accept candidates in the
 * Control show view — no manual mapping work needed for the obvious
 * cases.
 *
 * Algorithm notes:
 *  - Tokenisation: lower-case, split on non-alphanumerics, drop
 *    DE/EN stop-words and tokens shorter than 4 characters.
 *  - Similarity: |A ∩ B| / |A ∪ B| (Jaccard). Confidence-Score 0.0–1.0.
 *  - Already-mapped requirements are filtered out.
 *  - Default threshold: 0.20 — below that the overlap is too weak to
 *    be actionable.
 *
 * Kept intentionally simple: no external NLP, no embeddings, no
 * persistence. Runs in ~50 ms for 1 000 requirements, which is far
 * below any ergonomic ceiling.
 */
final class MappingSuggestionService
{
    public const DEFAULT_THRESHOLD = 0.20;
    public const DEFAULT_LIMIT_PER_FRAMEWORK = 5;

    /** Minimal DE + EN stop-word list — quality > completeness. */
    private const STOP_WORDS = [
        'eine', 'einen', 'einer', 'einem', 'der', 'die', 'das', 'den', 'dem',
        'und', 'oder', 'aber', 'mit', 'ohne', 'für', 'fuer', 'bei', 'nach',
        'werden', 'wird', 'wurde', 'worden', 'sein', 'sind', 'ist', 'war',
        'muss', 'soll', 'kann', 'darf', 'dies', 'diese', 'dieser', 'dieses',
        'alle', 'jede', 'jeden', 'jeder', 'jedes', 'auch', 'nicht', 'nur',
        'sowie', 'sondern', 'durch', 'über', 'unter', 'zwischen',
        // EN
        'the', 'and', 'for', 'with', 'without', 'must', 'should', 'shall',
        'this', 'that', 'these', 'those', 'from', 'into', 'onto', 'upon',
        'they', 'them', 'their', 'there', 'here', 'have', 'been', 'being',
        'will', 'would', 'could', 'should', 'may', 'might',
    ];

    public function __construct(
        private readonly ComplianceRequirementRepository $requirementRepository,
    ) {
    }

    /**
     * Suggest requirements for a Control, grouped by framework.
     *
     * @return array<string, list<array{
     *     requirement: ComplianceRequirement,
     *     confidence: float,
     *     framework: ComplianceFramework
     * }>>
     */
    public function suggestForControl(
        Control $control,
        float $threshold = self::DEFAULT_THRESHOLD,
        int $limitPerFramework = self::DEFAULT_LIMIT_PER_FRAMEWORK,
    ): array {
        $controlText = $this->collectControlText($control);
        $controlTokens = $this->tokenize($controlText);
        if ($controlTokens === []) {
            return [];
        }

        $byFramework = [];
        foreach ($this->requirementRepository->findAll() as $req) {
            $id = $req->getId();
            if ($id === null) {
                continue;
            }
            // Skip requirements already mapped to this Control.
            if ($req->getMappedControls()->contains($control)) {
                continue;
            }
            $framework = $req->getFramework();
            if (!$framework instanceof ComplianceFramework || !$framework->isActive()) {
                continue;
            }
            $tokens = $this->tokenize($this->collectRequirementText($req));
            if ($tokens === []) {
                continue;
            }
            $score = $this->jaccard($controlTokens, $tokens);
            if ($score < $threshold) {
                continue;
            }
            $code = (string) ($framework->getCode() ?? '');
            if ($code === '') {
                continue;
            }
            if (!isset($byFramework[$code])) {
                $byFramework[$code] = [];
            }
            $byFramework[$code][] = [
                'requirement' => $req,
                'confidence' => $score,
                'framework' => $framework,
            ];
        }

        foreach ($byFramework as $code => $rows) {
            usort(
                $rows,
                static fn(array $a, array $b): int => $b['confidence'] <=> $a['confidence']
            );
            $byFramework[$code] = array_slice($rows, 0, $limitPerFramework);
        }

        uksort(
            $byFramework,
            static fn(string $a, string $b): int => strcmp($a, $b)
        );

        return $byFramework;
    }

    /**
     * Total suggestion count across all frameworks — convenience for UI
     * teaser (*"12 Vorschläge prüfen"*) without iterating the nested
     * structure in Twig.
     *
     * @param array<string, list<array<string,mixed>>> $grouped
     */
    public function totalCount(array $grouped): int
    {
        $n = 0;
        foreach ($grouped as $rows) {
            $n += count($rows);
        }
        return $n;
    }

    private function collectControlText(Control $control): string
    {
        return implode(' ', array_filter([
            (string) $control->getName(),
            (string) $control->getDescription(),
            (string) $control->getCategory(),
            (string) $control->getControlId(),
        ]));
    }

    private function collectRequirementText(ComplianceRequirement $req): string
    {
        return implode(' ', array_filter([
            (string) $req->getTitle(),
            (string) $req->getDescription(),
            (string) $req->getCategory(),
            (string) $req->getRequirementId(),
        ]));
    }

    /**
     * @return list<string> Unique, lower-cased, stop-word-filtered tokens.
     */
    private function tokenize(string $text): array
    {
        $text = mb_strtolower($text, 'UTF-8');
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [];
        $stop = array_flip(self::STOP_WORDS);
        $tokens = [];
        foreach ($parts as $p) {
            if ($p === '' || mb_strlen($p, 'UTF-8') < 4) {
                continue;
            }
            if (isset($stop[$p])) {
                continue;
            }
            $tokens[$p] = true;
        }
        return array_keys($tokens);
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     */
    private function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }
        $setA = array_flip($a);
        $setB = array_flip($b);
        $intersect = count(array_intersect_key($setA, $setB));
        if ($intersect === 0) {
            return 0.0;
        }
        $union = count($setA) + count($setB) - $intersect;
        return $union > 0 ? round($intersect / $union, 4) : 0.0;
    }
}
