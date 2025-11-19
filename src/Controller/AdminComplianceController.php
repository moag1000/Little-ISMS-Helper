<?php

namespace App\Controller;

use App\Repository\ComplianceFrameworkRepository;
use App\Service\ComplianceFrameworkLoaderService;
use App\Service\ModuleConfigurationService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Admin wrapper for Compliance Framework Management
 * Integrates existing compliance framework functionality into admin panel
 */
#[Route('/admin/compliance')]
class AdminComplianceController extends AbstractController
{
    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceFrameworkLoaderService $frameworkLoaderService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ModuleConfigurationService $moduleConfigurationService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Compliance Framework Management Overview
     */
    #[Route('', name: 'admin_compliance_index', methods: ['GET'])]
    #[IsGranted('COMPLIANCE_VIEW')]
    public function index(): Response
    {
        $availableFrameworks = $this->frameworkLoaderService->getAvailableFrameworks();
        $statistics = $this->frameworkLoaderService->getFrameworkStatistics();

        $allModules = $this->moduleConfigurationService->getAllModules();
        $activeModules = $this->moduleConfigurationService->getActiveModules();

        return $this->render('admin/compliance/index.html.twig', [
            'available_frameworks' => $availableFrameworks,
            'statistics' => $statistics,
            'all_modules' => $allModules,
            'active_modules' => $activeModules,
        ]);
    }

    /**
     * Load/Activate a Compliance Framework
     */
    #[Route('/frameworks/load/{code}', name: 'admin_compliance_load_framework', methods: ['POST'])]
    #[IsGranted('COMPLIANCE_MANAGE')]
    public function loadFramework(string $code, Request $request): JsonResponse
    {
        // Validate CSRF token
        $token = $request->headers->get('X-CSRF-Token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('load_framework', $token))) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid CSRF token'
            ], 403);
        }

        $result = $this->frameworkLoaderService->loadFramework($code);

        if ($result['success']) {
            $this->addFlash('success', $result['message']);
        } else {
            $this->addFlash('error', $result['message']);
        }

        return new JsonResponse($result);
    }

    /**
     * Get Available Frameworks (API)
     */
    #[Route('/frameworks/available', name: 'admin_compliance_available_frameworks', methods: ['GET'])]
    #[IsGranted('COMPLIANCE_VIEW')]
    public function getAvailableFrameworks(): JsonResponse
    {
        $frameworks = $this->frameworkLoaderService->getAvailableFrameworks();
        $statistics = $this->frameworkLoaderService->getFrameworkStatistics();

        return new JsonResponse([
            'frameworks' => $frameworks,
            'statistics' => $statistics,
        ]);
    }

    /**
     * Delete a Compliance Framework
     */
    #[Route('/frameworks/delete/{code}', name: 'admin_compliance_delete_framework', methods: ['POST'])]
    #[IsGranted('COMPLIANCE_MANAGE')]
    public function deleteFramework(string $code, Request $request): JsonResponse
    {
        // Validate CSRF token
        $token = $request->headers->get('X-CSRF-Token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('delete_framework', $token))) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid CSRF token'
            ], 403);
        }

        try {
            $em = $this->frameworkRepository->getEntityManager();

            // Find the framework by code
            $framework = $this->frameworkRepository->findOneBy(['code' => $code]);

            if (!$framework) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Framework not found!'
                ], 404);
            }

            $frameworkName = $framework->getName();

            // Delete framework (cascade deletes requirements and mappings)
            $em->remove($framework);
            $em->flush();

            $this->addFlash('success', sprintf('Framework "%s" successfully deleted!', $frameworkName));

            return new JsonResponse([
                'success' => true,
                'message' => sprintf('Framework "%s" successfully deleted!', $frameworkName)
            ]);

        } catch (\Exception $e) {
            // Log full exception for debugging
            $this->logger->error('Framework deletion error', [
                'code' => $code,
                'exception' => $e->getMessage(),
                'class' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return new JsonResponse([
                'success' => false,
                'message' => 'Error deleting framework: ' . $e->getMessage(),
                'error_details' => get_class($e),
                'trace' => $e->getTraceAsString()
            ], 500);
        }
    }

    /**
     * Framework Statistics Dashboard
     */
    #[Route('/statistics', name: 'admin_compliance_statistics', methods: ['GET'])]
    #[IsGranted('COMPLIANCE_VIEW')]
    public function statistics(): Response
    {
        $statistics = $this->frameworkLoaderService->getFrameworkStatistics();
        $availableFrameworks = $this->frameworkLoaderService->getAvailableFrameworks();

        // Calculate compliance percentages per framework
        $complianceData = [];
        foreach ($availableFrameworks as $framework) {
            if ($framework['loaded']) {
                $dbFramework = $this->frameworkRepository->findOneBy(['code' => $framework['code']]);
                if ($dbFramework) {
                    $requirements = $dbFramework->getRequirements();
                    $total = count($requirements);
                    $assessed = 0;
                    $compliant = 0;

                    foreach ($requirements as $requirement) {
                        if ($requirement->getMappedControls()->count() > 0) {
                            $assessed++;
                            // Check if at least one mapped control is implemented
                            foreach ($requirement->getMappedControls() as $control) {
                                if ($control->getImplementationStatus() === 'implemented') {
                                    $compliant++;
                                    break;
                                }
                            }
                        }
                    }

                    $complianceData[] = [
                        'framework' => $framework,
                        'total_requirements' => $total,
                        'assessed_requirements' => $assessed,
                        'compliant_requirements' => $compliant,
                        'compliance_percentage' => $total > 0 ? round(($compliant / $total) * 100, 2) : 0,
                    ];
                }
            }
        }

        return $this->render('admin/compliance/statistics.html.twig', [
            'statistics' => $statistics,
            'compliance_data' => $complianceData,
        ]);
    }
}
