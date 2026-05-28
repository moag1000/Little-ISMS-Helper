<?php

declare(strict_types=1);

namespace App\Service\Tisax;

use App\Service\Tisax\Dto\ParsedWorkbookResult;
use App\Service\Tisax\Dto\VdaIsaControlRow;

/**
 * Validates a ParsedWorkbookResult before it proceeds to the mapper/commit.
 *
 * Rejection criteria (hard failures):
 *  - Fewer than 30 control rows (sanity — full ISA has ~70)
 *  - More than 20% of control IDs fail the VDA-ISA regex (x.y.z)
 *
 * Soft warnings (non-blocking):
 *  - Missing optional columns (evidenceHint, iso27001Ref, …)
 *  - Control IDs that look unusual but are not majority
 */
final class VdaIsaWorkbookValidator
{
    /** Minimum number of control rows (sanity check). */
    public const MIN_CONTROL_ROWS = 30;

    /** Reject if > this fraction of IDs are malformed. */
    public const MAX_INVALID_ID_RATIO = 0.20;

    /**
     * VDA-ISA control-ID pattern.
     * Matches: "1.1.1", "1.1.1.1", "1.1", "10.2.3" (up to 4 levels).
     */
    private const CONTROL_ID_REGEX = '/^\d{1,2}(\.\d{1,2}){1,3}$/';

    /**
     * @return array{ok: bool, errors: list<string>, warnings: list<string>}
     */
    public function validate(ParsedWorkbookResult $result): array
    {
        $errors   = [];
        $warnings = [];

        // Hard check 1 — minimum row count
        if ($result->getControlCount() < self::MIN_CONTROL_ROWS) {
            $errors[] = sprintf(
                'Too few control rows: %d found, minimum is %d. '
                . 'Please verify you are uploading the full ISA workbook.',
                $result->getControlCount(),
                self::MIN_CONTROL_ROWS,
            );
        }

        // Hard check 2 — control-ID format
        $invalidCount = 0;
        foreach ($result->controls as $row) {
            if (!preg_match(self::CONTROL_ID_REGEX, $row->controlId)) {
                $invalidCount++;
            }
        }

        if ($result->getControlCount() > 0) {
            $ratio = $invalidCount / $result->getControlCount();
            if ($ratio > self::MAX_INVALID_ID_RATIO) {
                $errors[] = sprintf(
                    '%d of %d control IDs do not match the expected format (e.g. "1.1.1"). '
                    . 'This workbook may not be a valid VDA-ISA file.',
                    $invalidCount,
                    $result->getControlCount(),
                );
            } elseif ($invalidCount > 0) {
                $warnings[] = sprintf(
                    '%d control ID(s) have an unusual format and will be imported as-is.',
                    $invalidCount,
                );
            }
        }

        // Soft check — optional columns
        $optionalColumns = ['iso27001Ref', 'evidenceHint', 'description'];
        foreach ($optionalColumns as $col) {
            if (!isset($result->detectedColumnMap[$col])) {
                $warnings[] = sprintf('Optional column "%s" was not found; field will be empty.', $col);
            }
        }

        // Soft check — both language columns present
        if (!isset($result->detectedColumnMap['titleDe']) && !isset($result->detectedColumnMap['titleEn'])) {
            $warnings[] = 'Neither a German nor an English question column was detected; control titles may be incomplete.';
        }

        return [
            'ok'       => $errors === [],
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * Quick header-only validation for use in controller before full parse.
     * Returns true when the detected column map contains at minimum a control-ID
     * column and at least one title/question column.
     *
     * @param array<string, int> $columnMap
     */
    public function headerMapIsValid(array $columnMap): bool
    {
        return isset($columnMap['controlId'])
            && (isset($columnMap['titleEn']) || isset($columnMap['titleDe']));
    }

    /**
     * Validate a single control row and return an error message or null.
     */
    public function validateRow(VdaIsaControlRow $row): ?string
    {
        if (!preg_match(self::CONTROL_ID_REGEX, $row->controlId)) {
            return sprintf('Row %d: invalid control ID "%s"', $row->rawRowIndex, $row->controlId);
        }
        if (strlen($row->title) > 1000) {
            return sprintf('Row %d: title exceeds 1000 characters', $row->rawRowIndex);
        }
        return null;
    }
}
