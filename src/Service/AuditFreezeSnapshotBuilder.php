<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ControlRepository;
use App\Repository\PortfolioSnapshotRepository;
use App\Repository\RiskRepository;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Builds the JSON payload captured in an AuditFreeze.
 *
 * Pure read-side service — no persistence, no side effects.
 * Tenant-strict: every query scopes to the passed Tenant.
 *
 * Historical limitation: fulfillment_percentage and risk levels are NOT
 * granularly persisted over time, so a freeze for a past Stichtag returns
 * CURRENT values. The limitation is carried in the payload meta section
 * so auditors know what they are looking at.
 *
 * @see docs/CM_JUNIOR_RESPONSE.md CM-8
 */
class AuditFreezeSnapshotBuilder
{
    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly ControlRepository $controlRepository,
        private readonly RiskRepository $riskRepository,
        private readonly PortfolioSnapshotRepository $portfolioSnapshotRepository,
    ) {
    }

    /**
     * Build the freeze payload for a tenant at a given Stichtag.
     *
     * @param list<string> $frameworkCodes
     * @return array<string,mixed>
     */
    public function build(Tenant $tenant, DateTimeInterface $stichtag, array $frameworkCodes): array
    {
        $stichtagImmutable = $stichtag instanceof DateTimeImmutable
            ? $stichtag
            : DateTimeImmutable::createFromInterface($stichtag);

        $generatedAt = new DateTimeImmutable();

        $frameworks = $this->loadFrameworks($frameworkCodes);

        return [
            'meta' => [
                'tenant' => $tenant->getName(),
                'tenant_code' => $tenant->getCode(),
                'stichtag' => $stichtagImmutable->format('Y-m-d'),
                'frameworks' => array_values(array_map(
                    static fn (ComplianceFramework $f): string => (string) $f->getCode(),
                    $frameworks
                )),
                'generated_at' => $generatedAt->format(DATE_ATOM),
                'historical_limitation' => 'Fulfillment-Werte und Risikostufen werden nicht granular historisiert. Der Freeze verwendet daher die aktuellen Werte zum Zeitpunkt der Erzeugung.',
            ],
            'soa_entries' => $this->buildSoaEntries($tenant),
            'requirement_fulfillments' => $this->buildRequirementFulfillments($tenant, $frameworks),
            'risks' => $this->buildRisks($tenant),
            'kpi' => $this->buildKpi($tenant, $frameworks),
            'portfolio_snapshot_refs' => $this->buildPortfolioSnapshotRefs($tenant, $stichtagImmutable),
        ];
    }

    /**
     * @param list<string> $frameworkCodes
     * @return list<ComplianceFramework>
     */
    private function loadFrameworks(array $frameworkCodes): array
    {
        if ($frameworkCodes === []) {
            return [];
        }
        $result = [];
        foreach ($frameworkCodes as $code) {
            $fw = $this->frameworkRepository->findOneBy(['code' => $code]);
            if ($fw instanceof ComplianceFramework) {
                $result[] = $fw;
            }
        }
        return $result;
    }

    /**
     * Statement-of-Applicability entries — one row per ISO 27001 Annex-A style
     * Control (tenant-scoped).
     *
     * @return list<array<string,mixed>>
     */
    private function buildSoaEntries(Tenant $tenant): array
    {
        $controls = $this->controlRepository->findByTenant($tenant);
        $entries = [];
        foreach ($controls as $control) {
            $entries[] = [
                'control_id' => (string) $control->getControlId(),
                'name' => (string) $control->getName(),
                'applicable' => (bool) $control->isApplicable(),
                'status' => (string) $control->getImplementationStatus(),
                'justification' => $control->getJustification(),
                'fulfillment_percentage' => (int) ($control->getImplementationPercentage() ?? 0),
            ];
        }
        // Sort by control_id for deterministic hashing
        usort($entries, static fn (array $a, array $b): int => strcmp($a['control_id'], $b['control_id']));
        return $entries;
    }

    /**
     * Requirement-level fulfillment per framework (NIS2 Art.21, ISO chapters, etc.).
     *
     * @param list<ComplianceFramework> $frameworks
     * @return list<array<string,mixed>>
     */
    private function buildRequirementFulfillments(Tenant $tenant, array $frameworks): array
    {
        $rows = [];
        foreach ($frameworks as $framework) {
            $fulfillments = $this->fulfillmentRepository->findByFrameworkAndTenant($framework, $tenant);
            foreach ($fulfillments as $fulfillment) {
                $requirement = $fulfillment->getRequirement();
                if ($requirement === null) {
                    continue;
                }
                $rows[] = [
                    'framework' => (string) $framework->getCode(),
                    'requirement_id' => (string) $requirement->getRequirementId(),
                    'title' => (string) $requirement->getTitle(),
                    'applicable' => $fulfillment->isApplicable(),
                    'applicability_justification' => $fulfillment->getApplicabilityJustification(),
                    'fulfillment_percentage' => $fulfillment->getFulfillmentPercentage(),
                    'status' => $fulfillment->getStatus(),
                ];
            }
        }
        usort($rows, static function (array $a, array $b): int {
            $cmp = strcmp($a['framework'], $b['framework']);
            return $cmp !== 0 ? $cmp : strcmp($a['requirement_id'], $b['requirement_id']);
        });
        return $rows;
    }

    /**
     * Risk register snapshot (top-level fields only).
     *
     * @return list<array<string,mixed>>
     */
    private function buildRisks(Tenant $tenant): array
    {
        $risks = $this->riskRepository->findByTenant($tenant);
        $rows = [];
        foreach ($risks as $risk) {
            $rows[] = [
                'id' => (int) $risk->getId(),
                'title' => (string) $risk->getTitle(),
                'inherent_level' => $risk->getInherentRiskLevel(),
                'residual_level' => $risk->getResidualRiskLevel(),
                'treatment_strategy' => $risk->getTreatmentStrategy()?->value ?? '',
                'status' => $risk->getStatus()?->value ?? '',
            ];
        }
        usort($rows, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);
        return $rows;
    }

    /**
     * Headline KPI block — compliance per framework plus aggregate ISMS health.
     *
     * @param list<ComplianceFramework> $frameworks
     * @return array<string,mixed>
     */
    private function buildKpi(Tenant $tenant, array $frameworks): array
    {
        $perFramework = [];
        $sum = 0.0;
        $count = 0;
        foreach ($frameworks as $framework) {
            $avg = $this->fulfillmentRepository->getAverageFulfillmentPercentage($framework, $tenant);
            $perFramework[(string) $framework->getCode()] = (int) round($avg);
            $sum += $avg;
            $count++;
        }
        $healthScore = $count > 0 ? (int) round($sum / $count) : 0;

        $risks = $this->riskRepository->findByTenant($tenant);
        $breach = 0;
        foreach ($risks as $risk) {
            // Risk >= 15 == high/critical (see Risk::getInherentRiskLevel threshold)
            if ($risk->getResidualRiskLevel() >= 15) {
                $breach++;
            }
        }

        return [
            'isms_health_score' => $healthScore,
            'per_framework_compliance' => $perFramework,
            'risk_appetite_breach_count' => $breach,
        ];
    }

    /**
     * @return list<int>
     */
    private function buildPortfolioSnapshotRefs(Tenant $tenant, DateTimeImmutable $stichtag): array
    {
        $ids = [];
        $rows = $this->portfolioSnapshotRepository->findBy([
            'tenant' => $tenant,
            'snapshotDate' => $stichtag->setTime(0, 0),
        ]);
        foreach ($rows as $row) {
            $id = $row->getId();
            if ($id !== null) {
                $ids[] = (int) $id;
            }
        }
        sort($ids);
        return $ids;
    }
}
