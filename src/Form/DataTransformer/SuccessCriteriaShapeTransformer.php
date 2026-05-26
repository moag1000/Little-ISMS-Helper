<?php

declare(strict_types=1);

namespace App\Form\DataTransformer;

use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/**
 * SuccessCriteriaShapeTransformer — round-trips BCExercise.successCriteria
 * between its two production shapes and a normalised "rich list" form for
 * the Stimulus JsonBuilder UI.
 *
 * Two shapes co-exist in production rows (ISO 22301 §8.6 c):
 *
 *   Shape B (legacy flat map):
 *     {"rtoMet": true, "rpoMet": true, "communicationEffective": false}
 *
 *   Shape A (rich list — preferred):
 *     [
 *       {"criterion": "RTO eingehalten", "target": "4h", "actual": "3h", "met": "met"},
 *       {"criterion": "Comms funktional", "target": "ja", "actual": "ja", "met": "met"}
 *     ]
 *
 * View model:  pretty-printed JSON string (Shape A, list of objects)
 * Norm model:  array|null — the original shape is preserved on save unless
 *              the user edits, in which case the rich Shape A is persisted.
 *
 * Why not a CollectionType? Two reasons:
 *   1. The shape is genuinely heterogeneous in existing rows; a strict
 *      CollectionType would mis-render Shape B rows.
 *   2. The custom Stimulus builder (_fa_success_criteria.html.twig) has
 *      cross-row UX (auto-prefill from BCPlan RTO/RPO, raw-JSON escape
 *      hatch) that CollectionType can't express.
 *
 * The textarea (hidden via the builder) carries the JSON wire format; this
 * transformer translates entity-array → view-JSON and view-JSON → entity-array.
 *
 * @implements DataTransformerInterface<array|null, string>
 */
final class SuccessCriteriaShapeTransformer implements DataTransformerInterface
{
    /**
     * Entity → view: emit Shape A JSON. Legacy Shape B rows are auto-coerced
     * so the builder always sees a list of objects.
     */
    public function transform(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '';
        }
        if (!is_array($value)) {
            return '';
        }

        $normalised = self::coerceToRichList($value);

        return json_encode($normalised, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * View → entity: accept either Shape A (preferred) or Shape B (legacy).
     * Empty input maps to null.
     */
    public function reverseTransform(mixed $value): ?array
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        try {
            $decoded = json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new TransformationFailedException(sprintf('JSON parse error: %s', $e->getMessage()));
        }
        if (!is_array($decoded)) {
            throw new TransformationFailedException('successCriteria must be a JSON object or array.');
        }
        if ($decoded === []) {
            return null;
        }

        // Detect shape:
        //   list (sequential int keys) → Shape A — pass through, light validation
        //   assoc                      → Shape B — pass through unchanged for BC
        if (array_is_list($decoded)) {
            return self::sanitiseRichList($decoded);
        }

        // Legacy Shape B — round-trip unchanged. Rows that haven't been
        // touched by the user keep their original key/bool form so existing
        // reports continue to work.
        return $decoded;
    }

    /**
     * Coerce either shape into the rich list form for the builder.
     *
     * @param array<mixed, mixed> $value
     * @return list<array{criterion: string, target: string, actual: string, met: string}>
     */
    public static function coerceToRichList(array $value): array
    {
        if ($value === []) {
            return [];
        }

        if (array_is_list($value)) {
            return self::sanitiseRichList($value);
        }

        // Shape B → Shape A: key becomes criterion, bool becomes met-flag.
        $result = [];
        foreach ($value as $key => $flag) {
            $met = match (true) {
                $flag === true || $flag === 'met' || $flag === 'yes' || $flag === 1 || $flag === '1' => 'met',
                $flag === false || $flag === 'not_met' || $flag === 'no' || $flag === 0 || $flag === '0' => 'not_met',
                default => 'unknown',
            };
            $result[] = [
                'criterion' => (string) $key,
                'target' => '',
                'actual' => '',
                'met' => $met,
            ];
        }

        return $result;
    }

    /**
     * Light sanitisation: ensure every row has the four expected keys with
     * string-coerced values. Extra keys are preserved (PropertyAccess
     * round-trip).
     *
     * @param list<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private static function sanitiseRichList(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $criterion = isset($row['criterion']) ? (string) $row['criterion'] : '';
            $target = isset($row['target']) ? (string) $row['target'] : '';
            $actual = isset($row['actual']) ? (string) $row['actual'] : '';
            $met = isset($row['met']) && in_array($row['met'], ['met', 'not_met', 'unknown'], true)
                ? $row['met']
                : 'unknown';

            // Skip rows that are entirely empty
            if ($criterion === '' && $target === '' && $actual === '' && $met === 'unknown') {
                continue;
            }

            $result[] = array_merge($row, [
                'criterion' => $criterion,
                'target' => $target,
                'actual' => $actual,
                'met' => $met,
            ]);
        }

        return $result;
    }
}
