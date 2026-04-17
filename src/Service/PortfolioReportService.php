<?php

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;

/**
 * Portfolio Report Service
 *
 * WS-4 (Data-Reuse Improvement Plan v1.1): Cross-Framework Portfolio-Report.
 * Builds a matrix of NIST CSF function-categories x activated Compliance-Frameworks
 * with average fulfillment percentage, gap counts and delta-to-previous-period.
 *
 * Pragmatic category mapping via keyword matching on ComplianceRequirement.category.
 * Default category: "Identify" (NIST CSF convention for unknown scope).
 *
 * @see docs/DATA_REUSE_IMPROVEMENT_PLAN.md WS-4
 */
class PortfolioReportService
{
    /**
     * NIST CSF Function-Categories used as matrix rows.
     *
     * @var list<string>
     */
    public const CATEGORIES = [
        'Govern',
        'Identify',
        'Protect',
        'Detect',
        'Respond',
        'Recover',
    ];

    /**
     * Category keyword-map. First match wins, checked top-to-bottom.
     * Keywords are matched case-insensitively against ComplianceRequirement::category.
     *
     * @var array<string, list<string>>
     */
    private const CATEGORY_KEYWORDS = [
        'Govern' => ['leadership', 'policy', 'governance', 'compliance', 'management review', 'context'],
        'Respond' => ['incident', 'response', 'breach', 'notification'],
        'Recover' => ['recovery', 'backup', 'continuity', 'bcm', 'restore', 'resilience'],
        'Detect' => ['monitoring', 'logging', 'detection', 'audit', 'review'],
        'Protect' => ['access', 'cryptography', 'encryption', 'training', 'awareness', 'physical', 'communication', 'supplier', 'operation'],
        'Identify' => ['risk', 'asset', 'inventory', 'threat', 'vulnerability'],
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
    ) {
    }

    /**
     * Build the cross-framework portfolio matrix.
     *
     * Return structure:
     *   [
     *     'rows' => [
     *       ['category' => 'Identify', 'cells' => [
     *           'ISO27001' => ['pct' => 80, 'delta' => +5, 'count' => 14, 'gaps' => 2],
     *           'NIS2' => [...],
     *       ]],
     *       ...
     *     ],
     *     'frameworks' => [
     *        ['code' => 'ISO27001', 'name' => 'ISO/IEC 27001:2022'],
     *        ...
     *     ],
     *     'stichtag' => \DateTimeInterface,
     *     'vorperiode' => \DateTimeInterface|null,
     *   ]
     *
     * Note: Cross-period delta is NOT implemented against historical fulfillment data
     * (would require audit-log snapshot); current implementation returns delta=0 when
     * stichtag == vorperiode, and 0 otherwise (placeholder until snapshot-infrastructure
     * arrives). See WS-4 for follow-up.
     *
     * @return array{
     *   rows: list<array{category: string, cells: array<string, array{pct: int, delta: int, count: int, gaps: int}>}>,
     *   frameworks: list<array{code: string, name: string}>,
     *   stichtag: \DateTimeInterface,
     *   vorperiode: \DateTimeInterface|null
     * }
     */
    public function buildMatrix(
        Tenant $tenant,
        \DateTimeInterface $stichtag,
        ?\DateTimeInterface $vorperiode = null,
    ): array {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();

        $frameworkMeta = [];
        foreach ($frameworks as $framework) {
            $frameworkMeta[] = [
                'code' => (string) $framework->getCode(),
                'name' => (string) $framework->getName(),
            ];
        }

        $rows = [];
        foreach (self::CATEGORIES as $category) {
            $cells = [];
            foreach ($frameworks as $framework) {
                $cells[(string) $framework->getCode()] = $this->computeCell($framework, $tenant, $category);
            }
            $rows[] = [
                'category' => $category,
                'cells' => $cells,
            ];
        }

        return [
            'rows' => $rows,
            'frameworks' => $frameworkMeta,
            'stichtag' => $stichtag,
            'vorperiode' => $vorperiode,
        ];
    }

    /**
     * Compute a single matrix cell: average fulfillment for requirements of a
     * given NIST-CSF category within a framework for a tenant.
     *
     * @return array{pct: int, delta: int, count: int, gaps: int}
     */
    private function computeCell(ComplianceFramework $framework, Tenant $tenant, string $category): array
    {
        $fulfillments = $this->fulfillmentRepository->findByFrameworkAndTenant($framework, $tenant);

        $matching = array_values(array_filter(
            $fulfillments,
            fn(ComplianceRequirementFulfillment $f): bool =>
                $f->getRequirement() instanceof ComplianceRequirement
                && $this->mapRequirementToCategory($f->getRequirement()) === $category
        ));

        $count = count($matching);

        if ($count === 0) {
            return ['pct' => 0, 'delta' => 0, 'count' => 0, 'gaps' => 0];
        }

        $sum = 0;
        $gaps = 0;
        foreach ($matching as $fulfillment) {
            $score = $fulfillment->isApplicable() ? $fulfillment->getFulfillmentPercentage() : 100;
            $sum += $score;
            if ($fulfillment->isApplicable() && $score < 100) {
                $gaps++;
            }
        }

        $pct = (int) round($sum / $count);

        // Delta-to-previous-period: currently same snapshot -> 0.
        // Hook: once FulfillmentSnapshot entity exists, compare historical pct here.
        $delta = 0;

        return [
            'pct' => $pct,
            'delta' => $delta,
            'count' => $count,
            'gaps' => $gaps,
        ];
    }

    /**
     * Map a requirement to one of the NIST CSF function-categories via
     * keyword match on ComplianceRequirement::category. Default: "Identify".
     */
    public function mapRequirementToCategory(ComplianceRequirement $requirement): string
    {
        $category = strtolower((string) $requirement->getCategory());
        $title = strtolower((string) $requirement->getTitle());
        $haystack = $category . ' ' . $title;

        if ($haystack === ' ') {
            return 'Identify';
        }

        foreach (self::CATEGORY_KEYWORDS as $csfFunction => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($haystack, $keyword)) {
                    return $csfFunction;
                }
            }
        }

        return 'Identify';
    }
}
