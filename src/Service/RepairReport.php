<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Immutable value object produced by BackupRepairService.
 *
 * Carries the full result of an analyze() or repair() run:
 * per-entity row counts, metadata-level issues, the recomputed
 * SHA-256 (repair mode only), and the final recoverability verdict.
 */
final readonly class RepairReport
{
    /**
     * @param int           $totalEntities      Number of entity types scanned.
     * @param int           $totalRows          Total rows across all entity types.
     * @param int           $recoveredRows      Rows that survived validation.
     * @param int           $lostRows           Rows that were dropped due to issues.
     * @param array<string, array{total: int, recovered: int, lost: int, issues: list<string>}> $perEntity
     *                                          Per-entity breakdown keyed by short-name.
     * @param list<string>  $metadataIssues     Top-level metadata problems (version missing, sha256 mismatch…).
     * @param string|null   $recomputedSha256   SHA-256 of cleaned data section (null when analyze-only).
     * @param bool          $isRecoverable      True when at least some rows can be fed to restore.
     */
    public function __construct(
        public int $totalEntities,
        public int $totalRows,
        public int $recoveredRows,
        public int $lostRows,
        public array $perEntity,
        public array $metadataIssues,
        public ?string $recomputedSha256,
        public bool $isRecoverable,
    ) {
    }

    /**
     * Serialise the report to a plain array (useful for --json output).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'totalEntities'    => $this->totalEntities,
            'totalRows'        => $this->totalRows,
            'recoveredRows'    => $this->recoveredRows,
            'lostRows'         => $this->lostRows,
            'perEntity'        => $this->perEntity,
            'metadataIssues'   => $this->metadataIssues,
            'recomputedSha256' => $this->recomputedSha256,
            'isRecoverable'    => $this->isRecoverable,
        ];
    }
}
