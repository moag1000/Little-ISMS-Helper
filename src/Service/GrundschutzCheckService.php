<?php

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * BSI IT-Grundschutz Check Service
 *
 * Provides Baustein-level Soll/Ist comparison for BSI IT-Grundschutz compliance.
 * Groups ComplianceRequirements by Baustein (e.g., "ISMS.1", "ORP.1") and calculates
 * implementation status per Baustein and per Absicherungsstufe.
 *
 * Architecture:
 * - Reads ComplianceRequirement entities where framework code = BSI_GRUNDSCHUTZ
 * - Extracts Baustein from requirementId (e.g., "ISMS.1.A1" -> Baustein "ISMS.1")
 * - Checks ComplianceRequirementFulfillment for tenant-specific implementation status
 * - Aggregates by Baustein, Schicht, and AnforderungsTyp (basis/standard/hoch)
 */
class GrundschutzCheckService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * Returns BSI Baustein completion status grouped by Baustein.
     *
     * Extracts Baustein from requirementId (e.g., "ISMS.1.A1" -> "ISMS.1"),
     * determines Schicht from Baustein prefix, and calculates fulfillment
     * status from ComplianceRequirementFulfillment records.
     *
     * @param Tenant|null $tenant Tenant to check (defaults to current tenant)
     * @return array<int, array{
     *     schicht: string,
     *     baustein: string,
     *     name: string,
     *     total_anforderungen: int,
     *     umgesetzt: int,
     *     teilweise: int,
     *     nicht_umgesetzt: int,
     *     completion_percentage: float,
     *     anforderungs_typ_breakdown: array<string, array{total: int, fulfilled: int}>
     * }>
     */
    public function getBausteinOverview(?Tenant $tenant = null): array
    {
        $tenant = $tenant ?? $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        $framework = $this->getGrundschutzFramework();
        if (!$framework instanceof ComplianceFramework) {
            return [];
        }

        $requirements = $this->requirementRepository->findByFramework($framework);
        $fulfillments = $this->getFulfillmentMap($framework, $tenant);

        // Group requirements by Baustein
        $bausteinGroups = [];
        foreach ($requirements as $requirement) {
            $baustein = $this->extractBaustein($requirement->getRequirementId());
            if ($baustein === null) {
                continue;
            }

            if (!isset($bausteinGroups[$baustein])) {
                $bausteinGroups[$baustein] = [
                    'requirements' => [],
                    'name' => $this->extractBausteinName($requirement),
                ];
            }
            $bausteinGroups[$baustein]['requirements'][] = $requirement;
        }

        // Build overview for each Baustein
        $overview = [];
        foreach ($bausteinGroups as $baustein => $group) {
            $schicht = $this->extractSchicht($baustein);
            $totalAnforderungen = count($group['requirements']);
            $umgesetzt = 0;
            $teilweise = 0;
            $nichtUmgesetzt = 0;

            $typBreakdown = [
                'basis' => ['total' => 0, 'fulfilled' => 0],
                'standard' => ['total' => 0, 'fulfilled' => 0],
                'hoch' => ['total' => 0, 'fulfilled' => 0],
            ];

            foreach ($group['requirements'] as $requirement) {
                $reqId = $requirement->getId();
                $anforderungsTyp = $requirement->getAnforderungsTyp() ?? 'standard';

                // Count by type
                if (isset($typBreakdown[$anforderungsTyp])) {
                    $typBreakdown[$anforderungsTyp]['total']++;
                }

                // Check fulfillment status
                $fulfillment = $fulfillments[$reqId] ?? null;
                if ($fulfillment instanceof ComplianceRequirementFulfillment) {
                    $percentage = $fulfillment->getFulfillmentPercentage();
                    if ($percentage >= 100) {
                        $umgesetzt++;
                        if (isset($typBreakdown[$anforderungsTyp])) {
                            $typBreakdown[$anforderungsTyp]['fulfilled']++;
                        }
                    } elseif ($percentage > 0) {
                        $teilweise++;
                    } else {
                        $nichtUmgesetzt++;
                    }
                } else {
                    // No fulfillment record = not implemented
                    $nichtUmgesetzt++;
                }
            }

            $completionPercentage = $totalAnforderungen > 0
                ? round(($umgesetzt / $totalAnforderungen) * 100, 1)
                : 0.0;

            $overview[] = [
                'schicht' => $schicht,
                'baustein' => $baustein,
                'name' => $group['name'],
                'total_anforderungen' => $totalAnforderungen,
                'umgesetzt' => $umgesetzt,
                'teilweise' => $teilweise,
                'nicht_umgesetzt' => $nichtUmgesetzt,
                'completion_percentage' => $completionPercentage,
                'anforderungs_typ_breakdown' => $typBreakdown,
            ];
        }

        // Sort by Baustein ID for consistent ordering
        usort($overview, fn(array $a, array $b) => strcmp($a['baustein'], $b['baustein']));

        return $overview;
    }

    /**
     * Returns compliance for a specific Absicherungsstufe (basis, standard, hoch).
     *
     * Filters ComplianceRequirements by their anforderungsTyp field and checks
     * fulfillment status for the current tenant.
     *
     * @param Tenant|null $tenant Tenant to check (defaults to current tenant)
     * @param string $stufe Absicherungsstufe: 'basis', 'standard', or 'hoch'
     * @return array{
     *     stufe: string,
     *     total_requirements: int,
     *     fulfilled: int,
     *     percentage: float,
     *     gaps: ComplianceRequirement[]
     * }
     */
    public function getAbsicherungsStufeCompliance(?Tenant $tenant = null, string $stufe = 'basis'): array
    {
        $tenant = $tenant ?? $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [
                'stufe' => $stufe,
                'total_requirements' => 0,
                'fulfilled' => 0,
                'percentage' => 0.0,
                'gaps' => [],
            ];
        }

        $framework = $this->getGrundschutzFramework();
        if (!$framework instanceof ComplianceFramework) {
            return [
                'stufe' => $stufe,
                'total_requirements' => 0,
                'fulfilled' => 0,
                'percentage' => 0.0,
                'gaps' => [],
            ];
        }

        // Get requirements filtered by anforderungsTyp
        $allRequirements = $this->requirementRepository->findByFramework($framework);
        $filteredRequirements = array_filter(
            $allRequirements,
            fn(ComplianceRequirement $r) => $r->getAnforderungsTyp() === $stufe
        );

        $fulfillments = $this->getFulfillmentMap($framework, $tenant);

        $totalRequirements = count($filteredRequirements);
        $fulfilled = 0;
        $gaps = [];

        foreach ($filteredRequirements as $requirement) {
            $fulfillment = $fulfillments[$requirement->getId()] ?? null;
            if ($fulfillment instanceof ComplianceRequirementFulfillment && $fulfillment->getFulfillmentPercentage() >= 100) {
                $fulfilled++;
            } else {
                $gaps[] = $requirement;
            }
        }

        $percentage = $totalRequirements > 0
            ? round(($fulfilled / $totalRequirements) * 100, 1)
            : 0.0;

        return [
            'stufe' => $stufe,
            'total_requirements' => $totalRequirements,
            'fulfilled' => $fulfilled,
            'percentage' => $percentage,
            'gaps' => $gaps,
        ];
    }

    /**
     * Returns high-level Grundschutz-Check summary.
     *
     * Aggregates Baustein overview into totals: fully implemented, partially
     * implemented, not started. Also provides per-Absicherungsstufe compliance
     * percentages for quick overview reporting.
     *
     * @param Tenant|null $tenant Tenant to check (defaults to current tenant)
     * @return array{
     *     total_bausteine: int,
     *     fully_implemented: int,
     *     partially_implemented: int,
     *     not_started: int,
     *     basis_compliance: float,
     *     standard_compliance: float,
     *     overall_compliance: float
     * }
     */
    public function getGrundschutzCheckSummary(?Tenant $tenant = null): array
    {
        $bausteinOverview = $this->getBausteinOverview($tenant);

        $totalBausteine = count($bausteinOverview);
        $fullyImplemented = 0;
        $partiallyImplemented = 0;
        $notStarted = 0;

        foreach ($bausteinOverview as $baustein) {
            if ($baustein['completion_percentage'] >= 100.0) {
                $fullyImplemented++;
            } elseif ($baustein['umgesetzt'] > 0 || $baustein['teilweise'] > 0) {
                $partiallyImplemented++;
            } else {
                $notStarted++;
            }
        }

        // Calculate per-Absicherungsstufe compliance
        $basisCompliance = $this->getAbsicherungsStufeCompliance($tenant, 'basis');
        $standardCompliance = $this->getAbsicherungsStufeCompliance($tenant, 'standard');

        // Overall compliance: weighted average across all Bausteine
        $totalAnforderungen = 0;
        $totalUmgesetzt = 0;
        foreach ($bausteinOverview as $baustein) {
            $totalAnforderungen += $baustein['total_anforderungen'];
            $totalUmgesetzt += $baustein['umgesetzt'];
        }
        $overallCompliance = $totalAnforderungen > 0
            ? round(($totalUmgesetzt / $totalAnforderungen) * 100, 1)
            : 0.0;

        return [
            'total_bausteine' => $totalBausteine,
            'fully_implemented' => $fullyImplemented,
            'partially_implemented' => $partiallyImplemented,
            'not_started' => $notStarted,
            'basis_compliance' => $basisCompliance['percentage'],
            'standard_compliance' => $standardCompliance['percentage'],
            'overall_compliance' => $overallCompliance,
        ];
    }

    /**
     * Get the BSI IT-Grundschutz framework entity.
     */
    private function getGrundschutzFramework(): ?ComplianceFramework
    {
        return $this->entityManager->getRepository(ComplianceFramework::class)
            ->findOneBy(['code' => 'BSI_GRUNDSCHUTZ']);
    }

    /**
     * Build a map of requirement ID -> fulfillment for efficient lookups.
     *
     * @return array<int, ComplianceRequirementFulfillment> Keyed by requirement entity ID
     */
    private function getFulfillmentMap(ComplianceFramework $framework, Tenant $tenant): array
    {
        $fulfillments = $this->fulfillmentRepository->findByFrameworkAndTenant($framework, $tenant);

        $map = [];
        foreach ($fulfillments as $fulfillment) {
            $requirement = $fulfillment->getRequirement();
            if ($requirement instanceof ComplianceRequirement) {
                $map[$requirement->getId()] = $fulfillment;
            }
        }

        return $map;
    }

    /**
     * Extract Baustein identifier from a requirement ID.
     *
     * Examples:
     *   "ISMS.1.A1" -> "ISMS.1"
     *   "ORP.1.A2"  -> "ORP.1"
     *   "SYS.1.1.A3" -> "SYS.1.1"
     *   "NET.1.1.A1" -> "NET.1.1"
     *
     * Pattern: everything before the last ".A<number>" segment.
     */
    private function extractBaustein(?string $requirementId): ?string
    {
        if ($requirementId === null || $requirementId === '') {
            return null;
        }

        // Match everything before ".A" followed by digits (the Anforderung number)
        if (preg_match('/^(.+)\.A\d+$/', $requirementId, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Extract Baustein name from a requirement's category or title.
     *
     * Falls back to the Baustein ID if no meaningful name can be derived.
     */
    private function extractBausteinName(ComplianceRequirement $requirement): string
    {
        // Category typically contains the Baustein name
        $category = $requirement->getCategory();
        if ($category !== null && $category !== '') {
            return $category;
        }

        // Fallback to Baustein ID
        return $this->extractBaustein($requirement->getRequirementId()) ?? 'Unbekannt';
    }

    /**
     * Extract Schicht (layer) from Baustein identifier.
     *
     * BSI IT-Grundschutz Schichten:
     * - ISMS = ISMS (Sicherheitsmanagement)
     * - ORP  = Organisation und Personal
     * - CON  = Konzepte und Vorgehensweisen
     * - OPS  = Betrieb
     * - DER  = Detektion und Reaktion
     * - APP  = Anwendungen
     * - SYS  = IT-Systeme
     * - IND  = Industrielle IT
     * - NET  = Netze und Kommunikation
     * - INF  = Infrastruktur
     */
    private function extractSchicht(string $baustein): string
    {
        $prefix = strtoupper(explode('.', $baustein)[0] ?? '');

        return match ($prefix) {
            'ISMS' => 'ISMS',
            'ORP' => 'ORP',
            'CON' => 'CON',
            'OPS' => 'OPS',
            'DER' => 'DER',
            'APP' => 'APP',
            'SYS' => 'SYS',
            'IND' => 'IND',
            'NET' => 'NET',
            'INF' => 'INF',
            default => $prefix !== '' ? $prefix : 'Unbekannt',
        };
    }
}
