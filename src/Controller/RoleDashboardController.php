<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\Persona\Persona;
use App\Security\Persona\PersonaResolver;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\RoleDashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Role-Based Dashboard Controller
 *
 * Provides specialized dashboards for different user roles.
 * Each dashboard shows KPIs and information most relevant to the role.
 *
 * Role-Scope Architecture (spec
 * `docs/superpowers/specs/2026-05-18-role-scope-architecture.md`): persona
 * dashboards are guarded via `TenantScopedAdminVoter::PERSONA_*` attributes
 * (module-visibility filter). Semantic equivalent of `#[IsGranted('ROLE_X')]`
 * — the voter resolves PERSONA_CISO → ROLE_CISO etc — but consistent with
 * the hub-catalog `requiredAttribute` convention.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/dashboards')]
class RoleDashboardController extends AbstractController
{
    public function __construct(
        private readonly RoleDashboardService $roleDashboardService,
        private readonly PersonaResolver $personaResolver,
    ) {
    }

    /**
     * Dashboard selector — redirects to the dashboard of the user's persona
     * (persona preview included).
     */
    #[Route('', name: 'app_role_dashboard_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $persona = $this->personaResolver->current() ?? Persona::USER;

        return $this->redirectToRoute($this->personaResolver->dashboardRouteFor($persona));
    }

    /**
     * CISO Dashboard
     *
     * Strategic view with compliance across frameworks, high-level risk posture,
     * and pending approvals requiring attention.
     */
    #[Route('/ciso', name: 'app_dashboard_ciso', methods: ['GET'])]
    #[IsGranted(TenantScopedAdminVoter::PERSONA_CISO)]
    public function cisoDashboard(): Response
    {
        $data = $this->roleDashboardService->getCisoDashboard();

        return $this->render('dashboards/ciso.html.twig', [
            'dashboard' => $data,
        ]);
    }

    /**
     * Risk Manager Dashboard
     *
     * Operational risk view with treatment pipeline, appetite monitoring,
     * and mitigation effectiveness tracking.
     */
    #[Route('/risk-manager', name: 'app_dashboard_risk_manager', methods: ['GET'])]
    #[IsGranted(TenantScopedAdminVoter::PERSONA_RISK)]
    public function riskManagerDashboard(): Response
    {
        $data = $this->roleDashboardService->getRiskManagerDashboard();

        return $this->render('dashboards/risk_manager.html.twig', [
            'dashboard' => $data,
        ]);
    }

    /**
     * Auditor Dashboard
     *
     * Evidence collection status, findings tracker, audit timeline,
     * and corrective action monitoring.
     */
    #[Route('/auditor', name: 'app_dashboard_auditor', methods: ['GET'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function auditorDashboard(): Response
    {
        $data = $this->roleDashboardService->getAuditorDashboard();

        return $this->render('dashboards/auditor.html.twig', [
            'dashboard' => $data,
        ]);
    }

    /**
     * Board Dashboard
     *
     * Executive summary with RAG status indicators, trend arrows,
     * and top critical items requiring board attention.
     */
    #[Route('/board', name: 'app_dashboard_board', methods: ['GET'])]
    #[IsGranted(TenantScopedAdminVoter::PERSONA_BOARD)]
    public function boardDashboard(): Response
    {
        $data = $this->roleDashboardService->getBoardDashboard();

        return $this->render('dashboards/board.html.twig', [
            'dashboard' => $data,
        ]);
    }
}
