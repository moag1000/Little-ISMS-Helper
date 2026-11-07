<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Attribute\RequiresModule;
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
 *   - BIA coverage, critical processes, active BC plans, exercises this year
 *     (KpiCatalog keys, same numbers as every other dashboard)
 *   - the officer's open duties (ObligationInbox, same list as My Day)
 *   - lifecycle-stuck items
 *   - the downloads the persona may take (ReportAccessVoter)
 *
 * Role gate: PERSONA_BCM (resolves to ROLE_BCM_OFFICER via TenantScopedAdminVoter).
 * Module gate: 'bcm' — controller is only meaningful when BCM module is active.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/dashboards/bcm', name: 'app_dashboard_bcm')]
#[IsGranted(TenantScopedAdminVoter::PERSONA_BCM)]
#[RequiresModule('bcm')]
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

        $lifecycleStuck   = $this->roleDashboardService->getLifecycleStuck();

        return $this->render('dashboards/bcm_officer.html.twig', [
            'dashboard' => [
                'lifecycle_stuck'   => $lifecycleStuck,
            ],
        ]);
    }
}
