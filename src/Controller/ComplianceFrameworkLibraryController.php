<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Job\LoadStarterPackJob;
use App\Service\ComplianceFrameworkLoaderService;
use App\Service\Job\AsyncJobDispatcher;
use App\Service\ModuleConfigurationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly TranslatorInterface $translator,
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

    /**
     * Starter-Pack — opt-in, one-click load of the baseline framework
     * catalogue + their cross-framework mappings, via an async job.
     *
     * Designed for a fresh tenant whose compliance/mapping area is still empty:
     * loads ISO 27001 + BSI IT-Grundschutz (always) and GDPR (only when the
     * `privacy` module is active), then seeds the applicable mappings. Idempotent
     * and reversible-by-design (data only) — safe to re-trigger.
     */
    #[Route('/compliance/frameworks/load-starter-pack', name: 'app_compliance_load_starter_pack', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function loadStarterPack(
        Request $request,
        AsyncJobDispatcher $asyncJobDispatcher,
    ): Response {
        if (!$this->isCsrfTokenValid('load_starter_pack', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans('common.csrf_error', [], 'messages'));

            return $this->redirectToRoute('app_compliance_framework_library');
        }

        $user = $this->getUser();
        $tenant = $user instanceof User ? $user->getTenant() : null;

        return $asyncJobDispatcher->dispatchWithProgress(
            request: $request,
            jobClass: LoadStarterPackJob::class,
            jobArgs: [
                'tenantId' => $tenant?->getId(),
                'userId' => $user instanceof User ? $user->getId() : null,
            ],
            jobName: 'compliance.starter_pack',
            payload: [
                '_label' => $this->translator->trans('compliance.starter_pack.progress_label', [], 'compliance'),
                '_subtitle' => $this->translator->trans('compliance.starter_pack.progress_subtitle', [], 'compliance'),
            ],
            returnUrl: $this->generateUrl('app_compliance_index'),
        );
    }
}
