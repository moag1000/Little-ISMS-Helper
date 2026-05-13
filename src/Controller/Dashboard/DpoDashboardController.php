<?php

declare(strict_types=1);

namespace App\Controller\Dashboard;

use App\Repository\DataBreachRepository;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Repository\ProcessingActivityRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * DPO persona dashboard — Art. 39 GDPR operational view.
 *
 * Surfaces:
 *   - Open data breaches (Art. 33/34) count + 72 h ticking
 *   - DPIAs in-progress (Art. 35)
 *   - Processing activities requiring DPIA
 *   - Processing activities due for review
 *   - Processing activities with third-country transfers
 *   - Quick actions (VVT export, DPIA list, data breaches)
 *
 * Module gate: 'privacy'. Role gate: ROLE_DPO.
 */
#[Route('/dashboards/dpo', name: 'app_dashboard_dpo')]
#[IsGranted('ROLE_DPO')]
final class DpoDashboardController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DataBreachRepository $dataBreachRepo,
        private readonly DataProtectionImpactAssessmentRepository $dpiaRepo,
        private readonly ProcessingActivityRepository $processingActivityRepo,
    ) {
    }

    public function __invoke(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException();
        }

        // Open data breaches (not yet completed)
        $openBreaches = $this->dataBreachRepo->findIncomplete($tenant);

        // Breaches with 72 h authority-notification clock ticking
        $ticking72h = $this->dataBreachRepo->findAuthorityNotification72hTicking($tenant);

        // DPIAs in review / draft
        $dpiasInProgress = array_merge(
            $this->dpiaRepo->findDrafts($tenant),
            $this->dpiaRepo->findInReview($tenant),
        );

        // Processing activities that need a DPIA but don't have one
        $activitiesNeedingDpia = $this->processingActivityRepo->findRequiringDPIA($tenant);

        // Processing activities due for periodic review
        $activitiesDueReview = $this->processingActivityRepo->findDueForReview($tenant);

        // Processing activities with third-country transfers (Art. 44-49 GDPR)
        $thirdCountryTransfers = $this->processingActivityRepo->findWithThirdCountryTransfers($tenant);

        return $this->render('dashboards/dpo.html.twig', [
            'dashboard' => [
                'open_breaches'            => $openBreaches,
                'open_breaches_count'      => count($openBreaches),
                'ticking_72h_count'        => count($ticking72h),
                'dpias_in_progress'        => $dpiasInProgress,
                'dpias_in_progress_count'  => count($dpiasInProgress),
                'activities_needing_dpia'  => $activitiesNeedingDpia,
                'activities_due_review'    => $activitiesDueReview,
                'third_country_transfers'  => $thirdCountryTransfers,
            ],
        ]);
    }
}
