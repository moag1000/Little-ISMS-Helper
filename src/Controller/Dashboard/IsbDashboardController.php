<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Repository\ComplianceFrameworkRepository;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\RoleDashboardService;
use App\Service\TenantContext;
use App\Service\Tisax\TisaxMaturityAssessmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ISB (Informationssicherheitsbeauftragter) persona dashboard — ISO 27001 operational view.
 *
 * Surfaces the ISB's day-to-day ISMS operational scope:
 *   - Open incidents and controls
 *   - Pending workflow approvals
 *   - Lifecycle-stuck items
 *   - Quick-links to ISMS core, controls, incidents, audits
 *
 * Role gate: PERSONA_ISB (resolves to ROLE_ISB via TenantScopedAdminVoter).
 *
 * Wave 5 / Part 2 — placeholder release: full KPI widgets planned in follow-up sprint.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/dashboards/isb', name: 'app_dashboard_isb')]
#[IsGranted(TenantScopedAdminVoter::PERSONA_ISB)]
final class IsbDashboardController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RoleDashboardService $roleDashboardService,
        private readonly TisaxMaturityAssessmentService $tisaxAssessment,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
    ) {
    }

    public function __invoke(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException();
        }

        $pendingApprovals = $this->roleDashboardService->getPendingApprovals();
        $lifecycleStuck   = $this->roleDashboardService->getLifecycleStuck();

        // TISAX IS-tier aggregate — ISB scope covers information_security chapters only
        $tisaxAggregate = null;
        $settings = $tenant->getSettings() ?? [];
        if ($settings['modules']['tisax'] ?? true) {
            $framework = $this->frameworkRepository->findOneBy(['code' => 'TISAX']);
            if ($framework !== null) {
                $agg = $this->tisaxAssessment->computeAggregate($framework, $tenant);
                if ($agg['total'] > 0) {
                    $tisaxAggregate = $agg;
                }
            }
        }

        return $this->render('dashboards/isb.html.twig', [
            'dashboard' => [
                'pending_approvals' => $pendingApprovals,
                'lifecycle_stuck'   => $lifecycleStuck,
                'tisax_aggregate'   => $tisaxAggregate,
            ],
        ]);
    }
}
