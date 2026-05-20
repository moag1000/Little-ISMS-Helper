<?php

declare(strict_types=1);

namespace App\Job;

use App\Service\DataIntegrityService;
use App\Service\SectionScanResultCache;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Async admin job: combined scan of the four health-check buckets
 * (risk / compliance / operational / data-quality) used to live
 * inline in {@see \App\Controller\Admin\DataRepairController::index()}.
 *
 * Persists per-bucket counts and a small preview to
 * {@see SectionScanResultCache} so the `/admin/data-repair/health`
 * sub-page renders from cache without paying for 4 × `findAll()` +
 * 4 × in-PHP filtering on every visit.
 *
 * Args: none.
 *
 * Writes:
 *   var/data_integrity/health.json via SectionScanResultCache
 */
final class ScanHealthIssuesJob implements AsyncJobInterface
{
    private const PREVIEW_LIMIT_PER_CHECK = 25;

    public function __construct(
        private readonly DataIntegrityService $dataIntegrityService,
        private readonly SectionScanResultCache $cache,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $startedAt = microtime(true);

        $filters = $this->entityManager->getFilters();
        $wasEnabled = $filters->isEnabled('tenant_filter');
        if ($wasEnabled) {
            $filters->disable('tenant_filter');
        }

        try {
            $ctx->progress(1, 4, 'Scanning risk health (ISO 27005)…');
            $risk = $this->dataIntegrityService->findRiskHealthIssues();

            $ctx->progress(2, 4, 'Scanning compliance health (GDPR / Art. 30)…');
            $compliance = $this->dataIntegrityService->findComplianceHealthIssues();

            $ctx->progress(3, 4, 'Scanning operational health (ISO 27001 Tier 2)…');
            $operational = $this->dataIntegrityService->findOperationalHealthIssues();

            $ctx->progress(4, 4, 'Scanning data quality (Tier 3)…');
            $dataQuality = $this->dataIntegrityService->findDataQualityIssues();

            $summarised = [
                'risk' => $this->summarise($risk),
                'compliance' => $this->summarise($compliance),
                'operational' => $this->summarise($operational),
                'data_quality' => $this->summarise($dataQuality),
            ];

            $grandTotal = 0;
            foreach ($summarised as $bucket) {
                $grandTotal += (int) ($bucket['total'] ?? 0);
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->cache->write(
                SectionScanResultCache::SECTION_HEALTH,
                [
                    'total' => $grandTotal,
                    'buckets' => $summarised,
                    'preview_limit' => self::PREVIEW_LIMIT_PER_CHECK,
                ],
                $durationMs,
            );

            $ctx->message(sprintf('Done. %d health issue(s) across 4 bucket(s).', $grandTotal));
        } finally {
            if ($wasEnabled && $this->entityManager->isOpen()) {
                $this->entityManager->getFilters()->enable('tenant_filter');
            }
        }
    }

    /**
     * Reduce a bucket of entity-graph results into a JSON-safe summary the
     * sub-page can render directly: per-check count + a small id+label preview.
     *
     * @param array<string, mixed> $bucket
     *
     * @return array{total: int, by_check: array<string, int>, preview: array<string, list<array{id: int|null, label: string}>>}
     */
    private function summarise(array $bucket): array
    {
        $byCheck = [];
        $preview = [];
        $total = 0;

        foreach ($bucket as $check => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            $count = count($rows);
            if ($count === 0) {
                continue;
            }
            $byCheck[$check] = $count;
            $total += $count;

            $rowPreview = [];
            $i = 0;
            foreach ($rows as $row) {
                if ($i >= self::PREVIEW_LIMIT_PER_CHECK) {
                    break;
                }
                $i++;
                $rowPreview[] = [
                    'id' => is_object($row) && method_exists($row, 'getId') ? (int) $row->getId() : null,
                    'label' => $this->bestLabel($row),
                ];
            }
            $preview[$check] = $rowPreview;
        }

        return [
            'total' => $total,
            'by_check' => $byCheck,
            'preview' => $preview,
        ];
    }

    private function bestLabel(mixed $row): string
    {
        if (is_object($row)) {
            if (method_exists($row, 'getTitle')) {
                $v = (string) $row->getTitle();
                if ($v !== '') {
                    return $v;
                }
            }
            if (method_exists($row, 'getName')) {
                $v = (string) $row->getName();
                if ($v !== '') {
                    return $v;
                }
            }
            if (method_exists($row, 'getId')) {
                return '#' . (string) $row->getId();
            }
            return (new \ReflectionClass($row))->getShortName();
        }
        if (is_array($row)) {
            return (string) ($row['label'] ?? $row['title'] ?? $row['name'] ?? (isset($row['id']) ? '#' . $row['id'] : ''));
        }
        return (string) $row;
    }
}
