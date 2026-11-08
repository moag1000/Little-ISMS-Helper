<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Counts for the data-integrity section of /quick-fix. Each check is wrapped on its own:
 * the schema may be broken, and a column-not-found in one check must not hide the others.
 * Only for a super admin or the installer token — the scan reads every organisation.
 */
final class QuickFixIntegritySummary
{
    public function __construct(
        private readonly DataIntegrityService $dataIntegrity,
    ) {
    }

    /**
     * @return array{orphan_count: int, orphans_by_type: array<string, int>, mismatch_count: int,
     *               duplicate_counts: array<string, int>, data_integrity_errors: list<string>}
     */
    public function summarise(): array
    {
        $summary = [
            'orphan_count' => 0,
            'orphans_by_type' => [],
            'mismatch_count' => 0,
            'duplicate_counts' => [],
            'data_integrity_errors' => [],
        ];
        try {
            foreach ($this->dataIntegrity->findAllOrphanedEntities() as $type => $entities) {
                $summary['orphans_by_type'][(string) $type] = count($entities);
                $summary['orphan_count'] += count($entities);
            }
        } catch (\Throwable $e) {
            $summary['data_integrity_errors'][] = 'orphans: ' . $e->getMessage();
        }
        try {
            $summary['mismatch_count'] = count($this->dataIntegrity->findBrokenReferences());
        } catch (\Throwable $e) {
            $summary['data_integrity_errors'][] = 'mismatches: ' . $e->getMessage();
        }
        try {
            foreach ($this->dataIntegrity->findDuplicateEntities() as $type => $groups) {
                $summary['duplicate_counts'][(string) $type] = count($groups);
            }
        } catch (\Throwable $e) {
            $summary['data_integrity_errors'][] = 'duplicates: ' . $e->getMessage();
        }

        return $summary;
    }

    /** The same keys, all empty: what a visitor without the right gets. */
    public static function hidden(): array
    {
        return [
            'orphan_count' => 0,
            'orphans_by_type' => [],
            'mismatch_count' => 0,
            'duplicate_counts' => [],
            'data_integrity_errors' => [],
        ];
    }
}
