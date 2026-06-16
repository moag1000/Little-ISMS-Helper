<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\ComplianceFrameworkLoaderService;
use App\Service\ModuleConfigurationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ComplianceFrameworkLibraryController
 *
 * Manager-accessible routes for the compliance framework library:
 * - Browse the catalogue of available frameworks (GET)
 * - Load a framework into the tenant's workspace (POST, CSRF-protected)
 *
 * These routes are deliberately NOT under /admin/ so the admin-role-scope
 * gate does not apply — ROLE_MANAGER is sufficient.
 *
 * Admin-only framework management continues to live in AdminComplianceController.
 */
class ComplianceFrameworkLibraryController extends AbstractController
{
    public function __construct(
        private readonly ComplianceFrameworkLoaderService $complianceFrameworkLoaderService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ModuleConfigurationService $moduleConfigurationService,
    ) {}

    /**
     * Framework Library — catalogue of available frameworks with Load buttons.
     * Accessible to ROLE_MANAGER (= implementers) in addition to admins.
     * Admins continue to access the same data via /admin/compliance.
     */
    #[Route('/compliance/frameworks', name: 'app_compliance_framework_library', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function frameworkLibrary(): Response
    {
        $availableFrameworks = $this->complianceFrameworkLoaderService->getAvailableFrameworks();
        $statistics = $this->complianceFrameworkLoaderService->getFrameworkStatistics();
        $allModules = $this->moduleConfigurationService->getAllModules();
        $activeModules = $this->moduleConfigurationService->getActiveModules();

        return $this->render('compliance/framework_library.html.twig', [
            'available_frameworks' => $availableFrameworks,
            'statistics' => $statistics,
            'all_modules' => $allModules,
            'active_modules' => $activeModules,
        ]);
    }

    /**
     * Load a compliance framework — non-admin route accessible to ROLE_MANAGER.
     * Mirrors AdminComplianceController::loadFramework but lives under /compliance
     * so the admin-role-scope gate does not apply.
     */
    #[Route('/compliance/frameworks/load/{code}', name: 'app_compliance_user_load_framework', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function loadFrameworkForManager(string $code, Request $request): JsonResponse
    {
        $token = $request->headers->get('X-CSRF-Token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('load_framework', $token))) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid CSRF token',
            ], Response::HTTP_FORBIDDEN);
        }

        $result = $this->complianceFrameworkLoaderService->loadFramework($code);

        if ($result['success']) {
            $this->addFlash('success', $result['message']);
        } else {
            $this->addFlash('error', $result['message']);
        }

        return new JsonResponse($result);
    }
}
