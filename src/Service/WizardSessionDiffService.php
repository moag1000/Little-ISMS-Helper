<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WizardSession;

/**
 * WizardSessionDiffService — V4-EF-3
 *
 * Computes a structured diff between two WizardSession snapshots.
 * Both sessions must belong to the same wizard type; the caller is
 * responsible for enforcing tenant isolation before passing sessions.
 *
 * Return format (per question/check item):
 *   [
 *     'section'     => string           // category key
 *     'section_name'=> string           // human-readable category name
 *     'question'    => string           // check key
 *     'label'       => string           // human-readable check label
 *     'oldAnswer'   => int|float|null   // score from $from session
 *     'newAnswer'   => int|float|null   // score from $to session
 *     'changeType'  => 'added'|'removed'|'changed'|'unchanged'
 *   ]
 */
final class WizardSessionDiffService
{
    /**
     * Diff two completed WizardSession snapshots.
     *
     * @return array{
     *     sections: array<string, array{name: string, items: array<int, array{question: string, label: string, oldAnswer: int|float|null, newAnswer: int|float|null, changeType: string}>}>,
     *     summary: array{added: int, removed: int, changed: int, unchanged: int, total: int},
     *     score_delta: float,
     *     from_score: int,
     *     to_score: int,
     * }
     */
    public function diff(WizardSession $from, WizardSession $to): array
    {
        $fromResults = $from->getAssessmentResults();   // array keyed by categoryKey
        $toResults   = $to->getAssessmentResults();

        // Collect all category keys from both snapshots
        $allCategories = array_unique(array_merge(
            array_keys($fromResults),
            array_keys($toResults),
        ));
        sort($allCategories);

        $sections = [];
        $summary  = ['added' => 0, 'removed' => 0, 'changed' => 0, 'unchanged' => 0];

        foreach ($allCategories as $catKey) {
            $fromCat = $fromResults[$catKey] ?? null;
            $toCat   = $toResults[$catKey]   ?? null;

            $sectionName = $toCat['name'] ?? $fromCat['name'] ?? $catKey;

            // Collect all check keys within this category
            $fromItems = $fromCat['items'] ?? [];
            $toItems   = $toCat['items']   ?? [];
            $allItems  = array_unique(array_merge(array_keys($fromItems), array_keys($toItems)));
            sort($allItems);

            $diffItems = [];

            foreach ($allItems as $itemKey) {
                $fromItem = $fromItems[$itemKey] ?? null;
                $toItem   = $toItems[$itemKey]   ?? null;

                $oldScore = $fromItem !== null ? ($fromItem['score'] ?? null) : null;
                $newScore = $toItem   !== null ? ($toItem['score']   ?? null) : null;
                $label    = $toItem['label'] ?? $fromItem['label'] ?? $itemKey;

                $changeType = $this->deriveChangeType($oldScore, $newScore);
                $summary[$changeType]++;

                $diffItems[] = [
                    'question'   => $itemKey,
                    'label'      => $label,
                    'oldAnswer'  => $oldScore,
                    'newAnswer'  => $newScore,
                    'changeType' => $changeType,
                ];
            }

            if ($diffItems !== []) {
                $sections[$catKey] = [
                    'name'  => $sectionName,
                    'items' => $diffItems,
                ];
            }
        }

        $summary['total'] = array_sum($summary);

        return [
            'sections'    => $sections,
            'summary'     => $summary,
            'score_delta' => round($to->getOverallScore() - $from->getOverallScore(), 1),
            'from_score'  => $from->getOverallScore(),
            'to_score'    => $to->getOverallScore(),
        ];
    }

    /**
     * Determine the changeType for a single item comparison.
     *
     * - added:     existed only in $to  (null in $from, value in $to)
     * - removed:   existed only in $from (value in $from, null in $to)
     * - changed:   values differ
     * - unchanged: values equal (or both null)
     */
    private function deriveChangeType(int|float|null $old, int|float|null $new): string
    {
        if ($old === null && $new !== null) {
            return 'added';
        }
        if ($new === null && $old !== null) {
            return 'removed';
        }
        if ($old != $new) {   // intentional loose comparison for int/float equality
            return 'changed';
        }
        return 'unchanged';
    }
}
