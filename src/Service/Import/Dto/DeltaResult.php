<?php

declare(strict_types=1);

namespace App\Service\Import\Dto;

/**
 * Immutable value object returned by DeltaCalculator.
 *
 * Categorises every row of a ParsedSpreadsheet into one of five buckets:
 * creates, updates, unchanged, deletes (optional), or errors.
 * A summary array with counts is computed automatically on construction.
 */
final readonly class DeltaResult
{
    /**
     * Rows that have no matching existing entity and will be created.
     *
     * Each element shape:
     *   ['rowNumber' => int, 'data' => array, 'entityId' => null, 'oldValues' => null, 'newValues' => array, 'diff' => null]
     *
     * @var list<array{rowNumber: int, data: array<string, mixed>, entityId: null, oldValues: null, newValues: array<string, mixed>, diff: null}>
     */
    public array $creates;

    /**
     * Rows that match an existing entity whose persisted values differ from the import row.
     *
     * Each element shape:
     *   ['rowNumber' => int, 'data' => array, 'entityId' => int, 'oldValues' => array, 'newValues' => array, 'diff' => array]
     *
     * diff contains only the changed fields: ['fieldName' => ['old' => mixed, 'new' => mixed]]
     *
     * @var list<array{rowNumber: int, data: array<string, mixed>, entityId: int, oldValues: array<string, mixed>, newValues: array<string, mixed>, diff: array<string, array{old: mixed, new: mixed}>}>
     */
    public array $updates;

    /**
     * Rows that match an existing entity and are identical — no action needed.
     *
     * @var list<array{rowNumber: int, data: array<string, mixed>, entityId: int, oldValues: array<string, mixed>, newValues: array<string, mixed>, diff: array<empty, empty>}>
     */
    public array $unchanged;

    /**
     * Entities in the database that were not represented in the import sheet.
     * Only populated when DeltaConfig::$includeDeletes is true.
     *
     * Each element shape: ['entityId' => int, 'snapshot' => array]
     *
     * @var list<array{entityId: int, snapshot: array<string, mixed>}>
     */
    public array $deletes;

    /**
     * Rows that failed mapper validation and were skipped.
     *
     * Each element shape:
     *   ['rowNumber' => int, 'data' => array, 'errors' => list<string>]
     *
     * @var list<array{rowNumber: int, data: array<string, mixed>, errors: list<string>}>
     */
    public array $errors;

    /**
     * Counts automatically derived from the five buckets above.
     *
     * Keys: 'creates', 'updates', 'unchanged', 'deletes', 'errors', 'total'
     *
     * @var array{creates: int, updates: int, unchanged: int, deletes: int, errors: int, total: int}
     */
    public array $summary;

    /**
     * @param list<array<string, mixed>> $creates
     * @param list<array<string, mixed>> $updates
     * @param list<array<string, mixed>> $unchanged
     * @param list<array<string, mixed>> $deletes
     * @param list<array<string, mixed>> $errors
     */
    public function __construct(
        array $creates,
        array $updates,
        array $unchanged,
        array $deletes,
        array $errors,
    ) {
        $this->creates   = $creates;
        $this->updates   = $updates;
        $this->unchanged = $unchanged;
        $this->deletes   = $deletes;
        $this->errors    = $errors;

        $this->summary = [
            'creates'   => count($creates),
            'updates'   => count($updates),
            'unchanged' => count($unchanged),
            'deletes'   => count($deletes),
            'errors'    => count($errors),
            'total'     => count($creates) + count($updates) + count($unchanged) + count($errors),
        ];
    }
}
