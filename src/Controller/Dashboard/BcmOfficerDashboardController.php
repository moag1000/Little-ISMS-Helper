<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\RoleDashboardService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * BCM-Officer persona dashboard — ISO 22301 operational view.
 *
 * Surfaces the BCM Officer's scope:
 *   - BC Plans status overview
 *   - BC Exercises due
 *   - Crisis-Team availability
 *   - Pending workflow approvals
 *   - Lifecycle-stuck items
 *
 * Role gate: PERSONA_BCM (resolves to ROLE_BCM_OFFICER via TenantScopedAdminVoter).
 * Module gate: 'bcm' — controller is only meaningful when BCM module is active.
 *
 * Wave 5 / Part 2 — placeholder release: full KPI widgets planned in follow-up sprint.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/dashboards/bcm', name: 'app_dashboard_bcm')]
#[IsGranted(TenantScopedAdminVoter::PERSONA_BCM)]
final class BcmOfficerDashboardController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RoleDashboardService $roleDashboardService,
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

        return $this->render('dashboards/bcm_officer.html.twig', [
            'dashboard' => [
                'pending_approvals' => $pendingApprovals,
                'lifecycle_stuck'   => $lifecycleStuck,
            ],
        ]);
    }
}
