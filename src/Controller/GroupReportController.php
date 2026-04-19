<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Control;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ControlRepository;
use App\Repository\RiskRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Phase 9.P1.7 + P1.8 — Group-level read-only reports intended for a
 * Konzern-ISB / Group-CISO sitting at a holding tenant. Access requires
 * ROLE_GROUP_CISO and at least one subsidiary below the current tenant.
 *
 * Why a separate controller: the per-tenant views (risk index,
 * incident list, SoA, ...) are driven by the TenantContext of the
 * logged-in user. The group reports instead traverse the subtree
 * explicitly and never mutate data.
 */
#[Route('/group-report', name: 'app_group_report_')]
#[IsGranted('ROLE_GROUP_CISO')]
final class GroupReportController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RiskRepository $riskRepository,
        private readonly ControlRepository $controlRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
    ) {
    }

    #[Route('/nis2-registration', name: 'nis2_registration', methods: ['GET'])]
    public function nis2Registration(): Response
    {
        $root = $this->tenantContext->getCurrentTenant();
        if (!$root instanceof Tenant) {
            throw $this->createAccessDeniedException('No active tenant');
        }

        $tree = $this->tenantContext->getAccessibleTenants();
        $stats = [
            'total' => count($tree),
            'essential' => 0,
            'important' => 0,
            'not_regulated' => 0,
            'unknown' => 0,
            'registered' => 0,
        ];
        foreach ($tree as $tenant) {
            $class = $tenant->getNis2Classification() ?? Tenant::NIS2_UNKNOWN;
            if (isset($stats[$class])) {
                $stats[$class]++;
            } else {
                $stats['unknown']++;
            }
            if ($tenant->getNis2RegisteredAt() !== null) {
                $stats['registered']++;
            }
        }

        return $this->render('group_report/nis2_registration.html.twig', [
            'root' => $root,
            'tenants' => $tree,
            'stats' => $stats,
        ]);
    }

    #[Route('/tree', name: 'tree', methods: ['GET'])]
    public function tree(): Response
    {
        $root = $this->tenantContext->getCurrentTenant();
        if (!$root instanceof Tenant) {
            throw $this->createAccessDeniedException('No active tenant');
        }

        // Intentionally NOT getRootParent(): a Group-CISO sitting on a
        // mid-tree tenant (e.g. a regional holding) must see only their
        // subtree — lateral access to sibling subsidiaries and upward
        // access to the top holding is a segregation violation.
        return $this->render('group_report/tree.html.twig', [
            'root' => $root,
            'current' => $root,
        ]);
    }

    /**
     * Phase 9.P2.2 — Top-N Konzernrisiken across the whole subtree.
     * Sorted by residual risk if the tenant/risk has residual values,
     * otherwise by inherent risk. Caps at 10 for the dashboard view.
     */
    #[Route('/risks', name: 'risks', methods: ['GET'])]
    public function risks(): Response
    {
        $root = $this->tenantContext->getCurrentTenant();
        if (!$root instanceof Tenant) {
            throw $this->createAccessDeniedException('No active tenant');
        }

        $tree = $this->tenantContext->getAccessibleTenants();
        $risks = $this->riskRepository->findBy(['tenant' => $tree], ['id' => 'DESC']);

        usort($risks, static function (Risk $a, Risk $b): int {
            $aScore = $a->getResidualRiskLevel() > 0 ? $a->getResidualRiskLevel() : $a->getInherentRiskLevel();
            $bScore = $b->getResidualRiskLevel() > 0 ? $b->getResidualRiskLevel() : $b->getInherentRiskLevel();
            return $bScore <=> $aScore;
        });

        $top = array_slice($risks, 0, 10);
        $byTenant = [];
        foreach ($risks as $risk) {
            $code = (string) $risk->getTenant()?->getCode();
            $byTenant[$code] = ($byTenant[$code] ?? 0) + 1;
        }

        return $this->render('group_report/risks.html.twig', [
            'root' => $root,
            'tenants' => $tree,
            'top_risks' => $top,
            'total_risks' => count($risks),
            'risks_by_tenant' => $byTenant,
        ]);
    }

    /**
     * Phase 9.P2.6 — Group-KPI-Matrix: framework compliance per tenant
     * in the subtree. Rows = tenant, columns = framework, cell =
     * fulfilled/applicable percentage. Uses the existing
     * ComplianceRequirementRepository stats helper so the numbers are
     * identical to the per-tenant compliance dashboards.
     */
    #[Route('/kpi-matrix', name: 'kpi_matrix', methods: ['GET'])]
    public function kpiMatrix(): Response
    {
        $root = $this->tenantContext->getCurrentTenant();
        if (!$root instanceof Tenant) {
            throw $this->createAccessDeniedException('No active tenant');
        }

        $tree = $this->tenantContext->getAccessibleTenants();
        $frameworks = $this->frameworkRepository->findBy([], ['name' => 'ASC']);

        $matrix = [];
        foreach ($tree as $tenant) {
            $row = [];
            foreach ($frameworks as $framework) {
                $stats = $this->requirementRepository->getFrameworkStatisticsForTenant($framework, $tenant);
                $applicable = (int) ($stats['applicable'] ?? 0);
                $fulfilled = (int) ($stats['fulfilled'] ?? 0);
                $row[$framework->getCode()] = [
                    'applicable' => $applicable,
                    'fulfilled' => $fulfilled,
                    'percentage' => $applicable > 0 ? (int) round(($fulfilled / $applicable) * 100) : null,
                ];
            }
            $matrix[$tenant->getCode()] = $row;
        }

        return $this->render('group_report/kpi_matrix.html.twig', [
            'root' => $root,
            'tenants' => $tree,
            'frameworks' => $frameworks,
            'matrix' => $matrix,
        ]);
    }

    /**
     * Phase 9.P2.7 — Group-SoA-Matrix: 93 controls × N tenants, showing
     * applicability and implementation status per cell. Rendered
     * read-only; per-tenant edits still happen in the per-tenant SoA.
     */
    #[Route('/soa-matrix', name: 'soa_matrix', methods: ['GET'])]
    public function soaMatrix(): Response
    {
        $root = $this->tenantContext->getCurrentTenant();
        if (!$root instanceof Tenant) {
            throw $this->createAccessDeniedException('No active tenant');
        }

        $tree = $this->tenantContext->getAccessibleTenants();
        $controls = $this->controlRepository->findBy(['tenant' => $tree]);

        // Build lookup: controlId => tenantCode => Control
        // ISO 27001 Annex A control IDs are shared across tenants, so a
        // tenant's row is identified by the Control.controlId value.
        $byControlId = [];
        $controlIdsSeen = [];
        foreach ($controls as $control) {
            $cid = (string) $control->getControlId();
            $controlIdsSeen[$cid] = true;
            $tenantCode = (string) $control->getTenant()?->getCode();
            $byControlId[$cid][$tenantCode] = $control;
        }
        ksort($controlIdsSeen);

        // Header KPIs: applicable / implemented counts across the tree
        $totals = [
            'cells' => 0,
            'applicable' => 0,
            'implemented' => 0,
        ];
        foreach ($controls as $control) {
            $totals['cells']++;
            if ($control->isApplicable()) {
                $totals['applicable']++;
            }
            if ($control->getImplementationStatus() === 'implemented') {
                $totals['implemented']++;
            }
        }

        return $this->render('group_report/soa_matrix.html.twig', [
            'root' => $root,
            'tenants' => $tree,
            'control_ids' => array_keys($controlIdsSeen),
            'matrix' => $byControlId,
            'totals' => $totals,
        ]);
    }
}
