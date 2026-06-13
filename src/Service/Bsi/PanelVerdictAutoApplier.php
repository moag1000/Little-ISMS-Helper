<?php

declare(strict_types=1);

namespace App\Service\Bsi;

use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Auto-apply ALL build-time expert-panel verdicts to the ComplianceMapping rows
 * at setup / library-import time.
 *
 * ## Why this exists
 * The 4-persona expert-panel adjudications shipped in
 * `fixtures/library/mappings/panel_verdicts/*_panel_v1.json` were previously only
 * applied by the manual CLI {@see \App\Command\Bsi\ApplyPanelVerdictsCommand}
 * (one fixture per invocation). On a fresh install nobody ran that command, so:
 *   - panel-REJECTED mapping pairs stayed active/visible (counted as coverage),
 *   - `ki_validiert` pairs never got `provenanceSource='panel'` + the elevated
 *     trust-tier surfaced by {@see IsoToBsiGapService::trustOf()},
 *   - `needs_review` pairs never landed in the review queue.
 *
 * This orchestrator discovers every `*_panel_v1.json` fixture, resolves its
 * source + target {@see ComplianceFramework} rows, and delegates each to
 * {@see PanelVerdictApplier::apply()}. It is the canonical "after mappings are
 * loaded → grade them" step.
 *
 * ## Scope
 * ONLY `*_panel_v1.json` verdict fixtures are applied. The sibling
 * `*_completeness_candidates_v1.json` files are *unrefuted proposals* and are
 * intentionally NOT auto-applied — they must stay out of active mappings.
 *
 * ## Idempotency
 * Re-running is safe. {@see PanelVerdictApplier} guards every verdict-state write
 * (already-panel/approved → `already_applied`, already-deprecated → no-op, etc.),
 * so repeated runs neither double-count nor churn rows.
 *
 * ## Graceful skipping
 * A fixture whose source/target framework (or whose requirement rows) are not yet
 * loaded is logged and skipped — never throws. This makes the applier safe to call
 * at the end of the library import even when only a subset of frameworks is present.
 *
 * @see ApplyAllPanelVerdictsCommand — thin CLI wrapper (app:apply-all-panel-verdicts)
 * @see PanelVerdictApplier          — per-fixture verdict writer
 */
final class PanelVerdictAutoApplier
{
    /** Relative glob (from project root) of all panel-verdict fixtures. */
    public const PANEL_VERDICT_GLOB = 'fixtures/library/mappings/panel_verdicts/*_panel_v1.json';

    /**
     * Framework-code aliases keyed by lowercased fixture code → canonical DB code.
     * Covers the cases where a fixture's `library.source_framework` /
     * `target_framework` (or filename token) uses a spelling that differs from
     * the canonical {@see \App\Service\ComplianceFrameworkLoaderService} code.
     */
    private const CODE_ALIASES = [
        'bsi-grundschutz'    => 'BSI_GRUNDSCHUTZ',
        'bsi_grundschutz'    => 'BSI_GRUNDSCHUTZ',
        'bsi-grundschutz-2024' => 'BSI_GRUNDSCHUTZ',
        'nis2-umsucg'        => 'NIS2UMSUCG',
        'nis2-art21'         => 'NIS2',
    ];

    /**
     * Filename-token → canonical DB code fallback, used only when a fixture has
     * NEITHER a `library` NOR a `provenance.source_framework`/`target_framework`
     * (e.g. the legacy `iso27001-2022_to_bsi-grundschutz_panel_v1.json`).
     * Keyed by the `<source>_to_<target>` token derived from the basename.
     */
    private const FILENAME_FRAMEWORK_FALLBACK = [
        'iso27001-2022' => 'ISO27001',
        'bsi-grundschutz' => 'BSI_GRUNDSCHUTZ',
        'dora'           => 'DORA',
        'nis2'           => 'NIS2',
    ];

    public function __construct(
        private readonly PanelVerdictApplierInterface $applier,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Discover and apply every `*_panel_v1.json` verdict fixture.
     *
     * @param bool $dryRun When true: compute per-fixture counts but write nothing.
     *
     * @return array{
     *     fixtures_total: int,
     *     applied: int,
     *     skipped: int,
     *     ki_validiert: int,
     *     rejected: int,
     *     needs_review: int,
     *     panel_discovered: int,
     *     already_applied: int,
     *     per_fixture: list<array{fixture: string, status: string, reason?: string, counts?: array<string,int>}>,
     * }
     */
    public function applyAll(bool $dryRun = false): array
    {
        $summary = [
            'fixtures_total'   => 0,
            'applied'          => 0,
            'skipped'          => 0,
            'ki_validiert'     => 0,
            'rejected'         => 0,
            'needs_review'     => 0,
            'panel_discovered' => 0,
            'already_applied'  => 0,
            'per_fixture'      => [],
        ];

        foreach ($this->discoverFixtures() as $absPath) {
            $summary['fixtures_total']++;
            $relPath = $this->toRelative($absPath);

            $codes = $this->resolveFrameworkCodes($absPath);
            if ($codes === null) {
                $summary['skipped']++;
                $summary['per_fixture'][] = [
                    'fixture' => $relPath,
                    'status'  => 'skipped',
                    'reason'  => 'source/target framework code not derivable from fixture',
                ];
                $this->logger?->warning(
                    'PanelVerdictAutoApplier: could not derive framework codes — skipping fixture.',
                    ['fixture' => $relPath],
                );
                continue;
            }

            [$sourceCode, $targetCode] = $codes;

            $source = $this->resolveFramework($sourceCode);
            $target = $this->resolveFramework($targetCode);

            if ($source === null || $target === null) {
                $summary['skipped']++;
                $summary['per_fixture'][] = [
                    'fixture' => $relPath,
                    'status'  => 'skipped',
                    'reason'  => sprintf(
                        'framework not loaded (source=%s%s, target=%s%s)',
                        $sourceCode,
                        $source === null ? ' [missing]' : '',
                        $targetCode,
                        $target === null ? ' [missing]' : '',
                    ),
                ];
                $this->logger?->info(
                    'PanelVerdictAutoApplier: framework(s) not loaded — skipping fixture (will surface once loaded).',
                    ['fixture' => $relPath, 'source' => $sourceCode, 'target' => $targetCode],
                );
                continue;
            }

            try {
                $counts = $this->applier->apply($relPath, $source, $target, $dryRun);
            } catch (\Throwable $e) {
                $summary['skipped']++;
                $summary['per_fixture'][] = [
                    'fixture' => $relPath,
                    'status'  => 'skipped',
                    'reason'  => 'apply() failed: ' . $e->getMessage(),
                ];
                $this->logger?->error(
                    'PanelVerdictAutoApplier: apply() threw — skipping fixture (non-fatal).',
                    ['fixture' => $relPath, 'error' => $e->getMessage()],
                );
                continue;
            }

            $summary['applied']++;
            $summary['ki_validiert']     += $counts['ki_validiert'];
            $summary['rejected']         += $counts['rejected'];
            $summary['needs_review']     += $counts['needs_review'];
            $summary['panel_discovered'] += $counts['panel_discovered'];
            $summary['already_applied']  += $counts['already_applied'];
            $summary['per_fixture'][] = [
                'fixture' => $relPath,
                'status'  => 'applied',
                'counts'  => $counts,
            ];
        }

        return $summary;
    }

    /**
     * Discover all `*_panel_v1.json` fixtures (sorted, deterministic).
     *
     * @return list<string> absolute paths
     */
    public function discoverFixtures(): array
    {
        $glob = $this->projectDir . DIRECTORY_SEPARATOR . self::PANEL_VERDICT_GLOB;
        $files = glob($glob) ?: [];
        sort($files);

        return $files;
    }

    /**
     * Derive [sourceCode, targetCode] for a fixture.
     *
     * Resolution order:
     *   1. `library.source_framework` / `library.target_framework`
     *   2. `provenance.source_framework` / `provenance.target_framework`
     *   3. filename `<source>_to_<target>_panel_v1.json` token fallback
     *
     * @return array{0: string, 1: string}|null null when codes cannot be derived
     */
    public function resolveFrameworkCodes(string $absPath): ?array
    {
        $raw = @file_get_contents($absPath);
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $library    = is_array($decoded['library'] ?? null) ? $decoded['library'] : [];
        $provenance = is_array($decoded['provenance'] ?? null) ? $decoded['provenance'] : [];

        $source = (string) ($library['source_framework'] ?? $provenance['source_framework'] ?? '');
        $target = (string) ($library['target_framework'] ?? $provenance['target_framework'] ?? '');

        if ($source === '' || $target === '') {
            [$fnSource, $fnTarget] = $this->frameworkCodesFromFilename($absPath);
            $source = $source !== '' ? $source : $fnSource;
            $target = $target !== '' ? $target : $fnTarget;
        }

        if ($source === '' || $target === '') {
            return null;
        }

        return [$source, $target];
    }

    /**
     * Derive [sourceCode, targetCode] from the `<source>_to_<target>_panel_v1` basename.
     *
     * @return array{0: string, 1: string}
     */
    private function frameworkCodesFromFilename(string $absPath): array
    {
        $basename = basename($absPath, '.json');
        // Strip the trailing _panel_v1 (and any _vN) suffix.
        $core = preg_replace('/_panel_v\d+$/', '', $basename) ?? $basename;

        $parts = explode('_to_', $core, 2);
        if (count($parts) !== 2) {
            return ['', ''];
        }

        $source = self::FILENAME_FRAMEWORK_FALLBACK[$parts[0]] ?? '';
        $target = self::FILENAME_FRAMEWORK_FALLBACK[$parts[1]] ?? '';

        return [$source, $target];
    }

    /**
     * Resolve a ComplianceFramework by code, mirroring the MappingLibraryLoader
     * strategy (alias → code → name), so fixture-code spellings line up with the
     * canonical DB rows.
     */
    private function resolveFramework(string $code): ?ComplianceFramework
    {
        $canonical = self::CODE_ALIASES[strtolower($code)] ?? $code;

        $fw = $this->frameworkRepository->findOneBy(['code' => $canonical]);
        if ($fw !== null) {
            return $fw;
        }

        // Loader BC: fall back to matching by name (some fixtures use human-readable codes).
        return $this->frameworkRepository->findOneBy(['name' => $code]);
    }

    private function toRelative(string $absPath): string
    {
        $prefix = $this->projectDir . DIRECTORY_SEPARATOR;
        if (str_starts_with($absPath, $prefix)) {
            return substr($absPath, strlen($prefix));
        }

        return $absPath;
    }
}
