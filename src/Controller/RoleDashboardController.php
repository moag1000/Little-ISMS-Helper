<?php

declare(strict_types=1);

namespace App\Controller;

use App\Security\Voter\TenantScopedAdminVoter;
use App\Service\RoleDashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Role-Based Dashboard Controller
 *
 * Phase 7D: Provides specialized dashboards for different user roles.
 * Each dashboard shows KPIs and information most relevant to the role.
 *
 * Role-Scope Architecture Phase 6 (spec
 * `docs/superpowers/specs/2026-05-18-role-scope-architecture.md`): persona
 * dashboards are guarded via `TenantScopedAdminVoter::PERSONA_*` attributes
 * (module-visibility filter). Semantic equivalent of `#[IsGranted('ROLE_X')]`
 * — the voter resolves PERSONA_CISO → ROLE_CISO etc — but consistent with
 * the hub-catalog `requiredAttribute` convention.
 */
#[Route('/dashboards')]
class RoleDashboardController extends AbstractController
{
    public function __construct(
        private readonly RoleDashboardService $roleDashboardService,
    ) {
    }

    /**
     * Dashboard selector - redirects to appropriate dashboard based on role
     */
    #[Route('', name: 'app_role_dashboard_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $recommendedDashboard = $this->roleDashboardService->getRecommendedDashboard();

        return match ($recommendedDashboard) {
            'ciso' => $this->redirectToRoute('app_dashboard_ciso'),
            'risk_manager' => $this->redirectToRoute('app_dashboard_risk_manager'),
            'auditor' => $this->redirectToRoute('app_dashboard_auditor'),
            'board' => $this->redirectToRoute('app_dashboard_board'),
            // Audit V3 W2-C5 — Compliance-Manager / DPO persona dashboard.
            'compliance_manager' => $this->redirectToRoute('app_dashboard_compliance_manager'),
            default => $this->redirectToRoute('app_dashboard'),
        };
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
    #[IsGranted(TenantScopedAdminVoter::PERSONA_CISO)]
    public function boardDashboard(): Response
    {
        $data = $this->roleDashboardService->getBoardDashboard();

        return $this->render('dashboards/board.html.twig', [
            'dashboard' => $data,
        ]);
    }

    // ==================== API Endpoints ====================

    /**
     * API: Get CISO dashboard data
     */
    #[Route('/api/ciso', name: 'app_dashboard_api_ciso', methods: ['GET'])]
    #[IsGranted(TenantScopedAdminVoter::PERSONA_CISO)]
    public function getCisoDashboardData(): JsonResponse
    {
        return new JsonResponse($this->roleDashboardService->getCisoDashboard());
    }

    /**
     * API: Get Risk Manager dashboard data
     */
    #[Route('/api/risk-manager', name: 'app_dashboard_api_risk_manager', methods: ['GET'])]
    #[IsGranted(TenantScopedAdminVoter::PERSONA_RISK)]
    public function getRiskManagerDashboardData(): JsonResponse
    {
        return new JsonResponse($this->roleDashboardService->getRiskManagerDashboard());
    }

    /**
     * API: Get Auditor dashboard data
     */
    #[Route('/api/auditor', name: 'app_dashboard_api_auditor', methods: ['GET'])]
    #[IsGranted('ROLE_AUDITOR')]
    public function getAuditorDashboardData(): JsonResponse
    {
        return new JsonResponse($this->roleDashboardService->getAuditorDashboard());
    }

    /**
     * API: Get Board dashboard data
     */
    #[Route('/api/board', name: 'app_dashboard_api_board', methods: ['GET'])]
    #[IsGranted(TenantScopedAdminVoter::PERSONA_CISO)]
    public function getBoardDashboardData(): JsonResponse
    {
        return new JsonResponse($this->roleDashboardService->getBoardDashboard());
    }
}
