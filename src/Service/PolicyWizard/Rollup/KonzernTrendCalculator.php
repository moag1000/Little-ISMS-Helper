<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Rollup;

use App\Entity\ComplianceFramework;
use App\Entity\Document;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\DocumentRepository;
use App\Repository\PolicyAcknowledgementRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * CISO-Executive Reporting (Task #130) — quarterly trend calculator.
 *
 * Persona-Walkthrough surfaced four reporting gaps at the Konzern level:
 *   1. Compliance-tab is a flat 0-100 % stacked bar — no YoY/QoQ trend.
 *   2. Outstanding-Actions tab lists rows — no severity heatmap.
 *   3. No One-Pager PDF for board meetings.
 *   4. No €/ALE column (P2 — skeleton placeholder is fine).
 *
 * This service tackles #1 by aggregating per-quarter counts of:
 *   - Policy documents (Document.uploadedAt, status != deleted/archived)
 *   - Approved policies (Document.status == 'published' OR 'approved')
 *   - Compliance score % (weighted mean across active frameworks at the
 *     end of each quarter — same calc as {@see KonzernRollupAggregator}
 *     but bucketed by quarter).
 *
 * Compliance-score history is APPROXIMATED from cumulative document-count
 * vs. published-document-count when the framework-stats repo only
 * exposes "current" snapshots. This is intentional (rather than over-
 * engineering a separate snapshot table for now): the One-Pager exists
 * for the BOARD, not for evidence-of-record. Auditors keep using
 * {@see KonzernRollupAggregator}'s point-in-time compliance score.
 *
 * Read-only — no flushes, no entity mutations.
 */
final class KonzernTrendCalculator
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly PolicyAcknowledgementRepository $policyAcknowledgementRepository,
        private readonly ComplianceFrameworkRepository $complianceFrameworkRepository,
        private readonly ComplianceRequirementRepository $complianceRequirementRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Build a {@see KonzernTrendReport} covering the last `$quartersBack`
     * quarters (default 8 = two full years). The current calendar quarter
     * is always included as the right-most bucket, even if no data has
     * been written yet — board-reports prefer "0 this quarter" over a
     * blank chart.
     */
    public function calculateQuarterlyTrend(
        Tenant $konzernRoot,
        int $quartersBack = 8,
        ?DateTimeImmutable $asOfDate = null,
    ): KonzernTrendReport {
        $asOf = $asOfDate ?? new DateTimeImmutable();
        $quartersBack = max(1, $quartersBack);

        $quarters = $this->buildQuarterRange($asOf, $quartersBack);
        $descendants = $konzernRoot->getAllSubsidiaries();
        $allTenants = array_merge([$konzernRoot], $descendants);
        // Skip the konzern root from per-subsidiary aggregation when it
        // has descendants — board view focuses on Toechter, not the
        // holding company itself.
        $tenantsForRows = $descendants !== [] ? $descendants : [$konzernRoot];

        $frameworks = $this->loadActiveFrameworks();

        $perSubsidiary = [];
        foreach ($tenantsForRows as $tenant) {
            $row = $this->buildSubsidiaryRow($tenant, $quarters, $frameworks);
            if ($row !== null) {
                $perSubsidiary[] = $row;
            }
        }

        $konzernAverage = $this->buildKonzernAverage($perSubsidiary, count($quarters));

        return new KonzernTrendReport(
            konzernRoot: $konzernRoot,
            quarters: $quarters,
            perSubsidiary: $perSubsidiary,
            konzernAverage: $konzernAverage,
            // SKELETON: ALE/€-column is null until the risk-manager
            // module exposes a per-tenant aggregate. Coupling point
            // documented in docs/plans/* (followup).
            estimatedAleEur: null,
        );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return list<string>  e.g. ["2024-Q3","2024-Q4","2025-Q1",...]
     */
    private function buildQuarterRange(DateTimeImmutable $asOf, int $quartersBack): array
    {
        $current = $this->quarterKeyFromDate($asOf);
        [$year, $q] = $this->parseQuarterKey($current);

        $list = [];
        for ($i = $quartersBack - 1; $i >= 0; $i--) {
            $offsetYear = $year;
            $offsetQ = $q - $i;
            while ($offsetQ <= 0) {
                $offsetQ += 4;
                $offsetYear--;
            }
            $list[] = sprintf('%04d-Q%d', $offsetYear, $offsetQ);
        }
        return $list;
    }

    private function quarterKeyFromDate(DateTimeInterface $date): string
    {
        $month = (int) $date->format('n');
        $q = (int) ceil($month / 3);
        return sprintf('%04d-Q%d', (int) $date->format('Y'), $q);
    }

    /**
     * @return array{0:int,1:int}  [year, quarter]
     */
    private function parseQuarterKey(string $key): array
    {
        if (preg_match('/^(\d{4})-Q([1-4])$/', $key, $m) !== 1) {
            return [(int) date('Y'), (int) ceil((int) date('n') / 3)];
        }
        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * @return list<ComplianceFramework>
     */
    private function loadActiveFrameworks(): array
    {
        $rows = [];
        foreach ($this->complianceFrameworkRepository->findBy(['active' => true]) as $f) {
            if ($f instanceof ComplianceFramework) {
                $rows[] = $f;
            }
        }
        return $rows;
    }

    /**
     * @param list<string> $quarters
     * @param list<ComplianceFramework> $frameworks
     * @return array<string, mixed>|null
     */
    private function buildSubsidiaryRow(Tenant $tenant, array $quarters, array $frameworks): ?array
    {
        $tenantId = $tenant->getId();
        if ($tenantId === null) {
            return null;
        }

        $documents = $this->documentRepository->findBy([
            'tenant'     => $tenant,
            'isArchived' => false,
        ]);

        // Audit V3 W2-C4: only count completed ACKNOWLEDGED rows; PENDING
        // campaign rows are tracked but unsigned and must not skew trends.
        $acks = $this->policyAcknowledgementRepository->findBy([
            'tenant' => $tenant,
            'status' => \App\Entity\PolicyAcknowledgement::STATUS_ACKNOWLEDGED,
        ]);

        // Map quarter-key → counts.
        $documentCounts = array_fill_keys($quarters, 0);
        $approvalCounts = array_fill_keys($quarters, 0);
        $cumulativeDocs = array_fill_keys($quarters, 0);
        $cumulativeApprovals = array_fill_keys($quarters, 0);

        foreach ($documents as $doc) {
            if (!$doc instanceof Document) {
                continue;
            }
            $status = $doc->getStatus();
            if (in_array($status, ['deleted', 'archived'], true)) {
                continue;
            }
            $uploaded = $doc->getUploadedAt();
            if (!$uploaded instanceof DateTimeInterface) {
                continue;
            }
            $key = $this->quarterKeyFromDate($uploaded);
            if (isset($documentCounts[$key])) {
                $documentCounts[$key]++;
                if (in_array($status, ['published', 'approved'], true)) {
                    $approvalCounts[$key]++;
                }
            }
        }

        // Build cumulative running totals so the trend is monotone-ish
        // (board likes "we have N policies" vs "we added M this quarter").
        $runningDocs = 0;
        $runningApprovals = 0;
        foreach ($quarters as $q) {
            $runningDocs += $documentCounts[$q];
            $runningApprovals += $approvalCounts[$q];
            $cumulativeDocs[$q] = $runningDocs;
            $cumulativeApprovals[$q] = $runningApprovals;
        }

        // Compliance-score approximation per quarter:
        //   score(q) = 100 * cumulative_approvals(q) / max(1, cumulative_docs(q))
        // This is a STATEMENT-OF-COVERAGE proxy — fine for the trend
        // sparkline and good enough for the board. The auditor view
        // continues to use {@see KonzernRollupAggregator} for a
        // requirement-by-requirement breakdown.
        $scores = [];
        foreach ($quarters as $q) {
            $denom = max(1, $cumulativeDocs[$q]);
            $score = 100.0 * ($cumulativeApprovals[$q] / $denom);
            $scores[] = round($score, 2);
        }

        // For the LATEST quarter, prefer the live framework-stats score
        // when available — that's what the auditor sees on the rollup
        // dashboard, so the One-Pager should agree on the right edge.
        if ($frameworks !== []) {
            $live = $this->liveComplianceScore($tenant, $frameworks);
            if ($live !== null) {
                $scores[count($scores) - 1] = $live;
            }
        }

        $latest = $scores[count($scores) - 1] ?? 0.0;
        $previous = count($scores) >= 2 ? $scores[count($scores) - 2] : $latest;
        $delta = round($latest - $previous, 2);
        $direction = match (true) {
            $delta > 0.5 => 'up',
            $delta < -0.5 => 'down',
            default => 'stable',
        };

        return [
            'tenant_id'                => $tenantId,
            'tenant_code'              => $tenant->getCode() ?? '',
            'tenant_name'              => $tenant->getName() ?? '',
            'document_counts'          => array_values($cumulativeDocs),
            'approval_counts'          => array_values($cumulativeApprovals),
            'compliance_scores'        => $scores,
            'ack_total'                => $this->countUniqueAcks($acks),
            'latest_score'             => $latest,
            'previous_score'           => $previous,
            'delta_percentage_points'  => $delta,
            'direction'                => $direction,
        ];
    }

    /**
     * @param list<PolicyAcknowledgement> $acks
     */
    private function countUniqueAcks(array $acks): int
    {
        $seen = [];
        foreach ($acks as $ack) {
            if (!$ack instanceof PolicyAcknowledgement) {
                continue;
            }
            $key = ($ack->getDocument()?->getId() ?? 0) . ':' . ($ack->getUser()?->getId() ?? 0);
            $seen[$key] = true;
        }
        return count($seen);
    }

    /**
     * Live framework-stats compliance score (weighted mean across all
     * active frameworks). Null when no framework returns usable stats —
     * the caller falls back to the cumulative-coverage proxy.
     *
     * @param list<ComplianceFramework> $frameworks
     */
    private function liveComplianceScore(Tenant $tenant, array $frameworks): ?float
    {
        $totalApplicable = 0;
        $totalFulfilled = 0;
        foreach ($frameworks as $framework) {
            try {
                $stats = $this->complianceRequirementRepository->getFrameworkStatisticsForTenant(
                    $framework,
                    $tenant,
                );
            } catch (\Throwable $error) {
                $this->logger->debug(
                    'KonzernTrendCalculator: framework stats unavailable; skipping',
                    [
                        'tenant_id'    => $tenant->getId(),
                        'framework_id' => $framework->getId(),
                        'error'        => $error->getMessage(),
                    ],
                );
                continue;
            }
            $totalApplicable += (int) ($stats['applicable'] ?? 0);
            $totalFulfilled += (int) ($stats['fulfilled'] ?? 0);
        }
        if ($totalApplicable === 0) {
            return null;
        }
        return round(($totalFulfilled / $totalApplicable) * 100, 2);
    }

    /**
     * @param list<array<string, mixed>> $perSubsidiary
     * @return array<string, mixed>
     */
    private function buildKonzernAverage(array $perSubsidiary, int $quarterCount): array
    {
        if ($perSubsidiary === [] || $quarterCount === 0) {
            return [
                'document_counts'         => array_fill(0, $quarterCount, 0),
                'approval_counts'         => array_fill(0, $quarterCount, 0),
                'compliance_scores'       => array_fill(0, $quarterCount, 0.0),
                'latest_score'            => 0.0,
                'previous_score'          => 0.0,
                'delta_percentage_points' => 0.0,
                'direction'               => 'stable',
            ];
        }

        $documentTotals = array_fill(0, $quarterCount, 0);
        $approvalTotals = array_fill(0, $quarterCount, 0);
        $scoreSums = array_fill(0, $quarterCount, 0.0);

        foreach ($perSubsidiary as $row) {
            foreach ($row['document_counts'] as $idx => $count) {
                $documentTotals[$idx] += (int) $count;
            }
            foreach ($row['approval_counts'] as $idx => $count) {
                $approvalTotals[$idx] += (int) $count;
            }
            foreach ($row['compliance_scores'] as $idx => $score) {
                $scoreSums[$idx] += (float) $score;
            }
        }

        $n = count($perSubsidiary);
        $scores = [];
        foreach ($scoreSums as $sum) {
            $scores[] = round($sum / max(1, $n), 2);
        }

        $latest = $scores[$quarterCount - 1] ?? 0.0;
        $previous = $quarterCount >= 2 ? $scores[$quarterCount - 2] : $latest;
        $delta = round($latest - $previous, 2);
        $direction = match (true) {
            $delta > 0.5 => 'up',
            $delta < -0.5 => 'down',
            default => 'stable',
        };

        return [
            'document_counts'         => $documentTotals,
            'approval_counts'         => $approvalTotals,
            'compliance_scores'       => $scores,
            'latest_score'            => $latest,
            'previous_score'          => $previous,
            'delta_percentage_points' => $delta,
            'direction'               => $direction,
        ];
    }
}
