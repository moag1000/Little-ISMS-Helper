<?php

declare(strict_types=1);

namespace App\Controller\Authority;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Service\Authority\AuthorityHubService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * F36 — EU-Behörden-Reporting-Hub Controller.
 *
 * Aggregates all EU authority reporting obligations for the current tenant
 * and presents them on a single overview page with status per authority.
 *
 * Sources aggregated:
 *  - F25 VVT-BfDI Export (DSGVO Art. 30)
 *  - F26 Behörden-Templates (BSI/BfDI/LfDI)
 *  - F29 NIS-2 BSI-Portal yearly registration
 *  - F30 DORA RoI XBRL yearly submission
 *
 * Module gate: eu_authority_reporting
 * RBAC: ROLE_MANAGER
 */
#[Route('/authority/hub', name: 'authority_hub_')]
#[IsGranted('ROLE_MANAGER')]
class AuthorityHubController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly AuthorityHubService $hubService,
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    // ─── Index ────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('eu_authority_reporting')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context available.');
        }

        $obligations = $this->hubService->getReportingObligationsForTenant($tenant);
        $summary = $this->hubService->getStatusSummary($tenant);

        return $this->render('authority/hub/index.html.twig', [
            'obligations' => $obligations,
            'summary'     => $summary,
        ]);
    }
}
