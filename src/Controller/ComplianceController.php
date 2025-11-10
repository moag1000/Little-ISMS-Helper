<?php

namespace App\Controller;

use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ComplianceMappingRepository;
use App\Service\ComplianceAssessmentService;
use App\Service\ComplianceMappingService;
use App\Service\ComplianceFrameworkLoaderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/compliance')]
class ComplianceController extends AbstractController
{
    public function __construct(
        private ComplianceFrameworkRepository $frameworkRepository,
        private ComplianceRequirementRepository $requirementRepository,
        private ComplianceMappingRepository $mappingRepository,
        private ComplianceAssessmentService $assessmentService,
        private ComplianceMappingService $mappingService,
        private ComplianceFrameworkLoaderService $frameworkLoaderService
    ) {}

    #[Route('/', name: 'app_compliance_index')]
    public function index(): Response
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $overview = $this->frameworkRepository->getComplianceOverview();
        $mappingStats = $this->mappingRepository->getMappingStatistics();

        // Calculate total data reuse value
        $totalTimeSavings = 0;
        foreach ($frameworks as $framework) {
            $requirements = $this->requirementRepository->findApplicableByFramework($framework);
            foreach ($requirements as $requirement) {
                $reuseValue = $this->mappingService->calculateDataReuseValue($requirement);
                $totalTimeSavings += $reuseValue['estimated_hours_saved'];
            }
        }

        return $this->render('compliance/index.html.twig', [
            'frameworks' => $frameworks,
            'overview' => $overview,
            'mapping_stats' => $mappingStats,
            'total_time_savings' => $totalTimeSavings,
            'total_days_savings' => round($totalTimeSavings / 8, 1),
        ]);
    }

    #[Route('/framework/{id}', name: 'app_compliance_framework', requirements: ['id' => '\d+'])]
    public function frameworkDashboard(int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $dashboard = $this->assessmentService->getComplianceDashboard($framework);
        $requirements = $this->requirementRepository->findByFramework($framework);

        return $this->render('compliance/framework_dashboard.html.twig', [
            'framework' => $framework,
            'dashboard' => $dashboard,
            'requirements' => $requirements,
        ]);
    }

    #[Route('/framework/{id}/gaps', name: 'app_compliance_gaps', requirements: ['id' => '\d+'])]
    public function gapAnalysis(int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $gaps = $this->requirementRepository->findGapsByFramework($framework);
        $criticalGaps = $this->requirementRepository->findByFrameworkAndPriority($framework, 'critical');

        // Analyze each gap for detailed insights
        $gapAnalysis = [];
        foreach ($gaps as $gap) {
            $analysis = $this->assessmentService->assessRequirement($gap);
            $gapAnalysis[] = [
                'requirement' => $gap,
                'analysis' => $analysis,
            ];
        }

        return $this->render('compliance/gap_analysis.html.twig', [
            'framework' => $framework,
            'gaps' => $gapAnalysis,
            'critical_gaps' => $criticalGaps,
            'total_gaps' => count($gaps),
        ]);
    }

    #[Route('/framework/{id}/data-reuse', name: 'app_compliance_data_reuse', requirements: ['id' => '\d+'])]
    public function dataReuseInsights(int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $requirements = $this->requirementRepository->findApplicableByFramework($framework);
        $dataReuseAnalysis = [];
        $totalTimeSavings = 0;

        foreach ($requirements as $requirement) {
            $analysis = $this->mappingService->getDataReuseAnalysis($requirement);
            $reuseValue = $this->mappingService->calculateDataReuseValue($requirement);

            $dataReuseAnalysis[] = [
                'requirement' => $requirement,
                'analysis' => $analysis,
                'value' => $reuseValue,
            ];

            $totalTimeSavings += $reuseValue['estimated_hours_saved'];
        }

        return $this->render('compliance/data_reuse_insights.html.twig', [
            'framework' => $framework,
            'analysis' => $dataReuseAnalysis,
            'total_time_savings' => $totalTimeSavings,
            'total_days_savings' => round($totalTimeSavings / 8, 1),
        ]);
    }

    #[Route('/cross-framework', name: 'app_compliance_cross_framework')]
    public function crossFrameworkMappings(): Response
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $crossMappings = [];
        $coverageMatrix = [];

        // Generate cross-framework coverage matrix
        foreach ($frameworks as $sourceFramework) {
            foreach ($frameworks as $targetFramework) {
                if ($sourceFramework->getId() === $targetFramework->getId()) {
                    continue;
                }

                $coverage = $this->mappingRepository->calculateFrameworkCoverage(
                    $sourceFramework,
                    $targetFramework
                );

                $coverageMatrix[$sourceFramework->getCode()][$targetFramework->getCode()] = $coverage;

                // Get detailed mappings
                $mappings = $this->mappingRepository->findCrossFrameworkMappings(
                    $sourceFramework,
                    $targetFramework
                );

                if (!empty($mappings)) {
                    $crossMappings[] = [
                        'source' => $sourceFramework,
                        'target' => $targetFramework,
                        'mappings' => $mappings,
                        'coverage' => $coverage,
                    ];
                }
            }
        }

        return $this->render('compliance/cross_framework.html.twig', [
            'frameworks' => $frameworks,
            'cross_mappings' => $crossMappings,
            'coverage_matrix' => $coverageMatrix,
        ]);
    }

    #[Route('/transitive-compliance', name: 'app_compliance_transitive')]
    public function transitiveCompliance(): Response
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $transitiveAnalysis = [];
        $mappingMatrix = [];

        // Build mapping coverage matrix for template
        foreach ($frameworks as $sourceFramework) {
            foreach ($frameworks as $targetFramework) {
                if ($sourceFramework->getId() === $targetFramework->getId()) {
                    continue;
                }

                // Calculate coverage for matrix
                $coverage = $this->mappingRepository->calculateFrameworkCoverage(
                    $sourceFramework,
                    $targetFramework
                );
                $mappingMatrix[$sourceFramework->getId()][$targetFramework->getId()] = [
                    'coverage' => $coverage,
                    'has_mapping' => $coverage > 0
                ];

                // Transitive analysis
                $transitive = $this->mappingRepository->getTransitiveCompliance(
                    $sourceFramework,
                    $targetFramework
                );

                if ($transitive['requirements_helped'] > 0) {
                    $transitiveAnalysis[] = $transitive;
                }
            }
        }

        return $this->render('compliance/transitive_compliance.html.twig', [
            'frameworks' => $frameworks,
            'transitive_analysis' => $transitiveAnalysis,
            'mapping_matrix' => $mappingMatrix,
            'total_relationships' => count($transitiveAnalysis),
            'transitive_compliance' => array_sum(array_column($transitiveAnalysis, 'requirements_helped')),
            'leverage_opportunities' => [],
        ]);
    }

    #[Route('/compare', name: 'app_compliance_compare')]
    public function compareFrameworks(Request $request): Response
    {
        $frameworks = $this->frameworkRepository->findActiveFrameworks();

        $selectedFramework1 = null;
        $selectedFramework2 = null;
        $comparison = null;
        $framework1Requirements = 0;
        $framework2Requirements = 0;
        $commonRequirements = 0;

        $framework1Id = $request->query->get('framework1');
        $framework2Id = $request->query->get('framework2');

        if ($framework1Id && $framework2Id) {
            $selectedFramework1 = $this->frameworkRepository->find($framework1Id);
            $selectedFramework2 = $this->frameworkRepository->find($framework2Id);

            if ($selectedFramework1 && $selectedFramework2) {
                $comparison = $this->assessmentService->compareFrameworks([$selectedFramework1, $selectedFramework2]);
                $framework1Requirements = count($selectedFramework1->getRequirements());
                $framework2Requirements = count($selectedFramework2->getRequirements());
                // Calculate common requirements (simplified - you may want to enhance this)
                $commonRequirements = 0;
            }
        }

        return $this->render('compliance/compare.html.twig', [
            'frameworks' => $frameworks,
            'selectedFramework1' => $selectedFramework1,
            'selectedFramework2' => $selectedFramework2,
            'comparison' => $comparison,
            'framework1Requirements' => $framework1Requirements,
            'framework2Requirements' => $framework2Requirements,
            'commonRequirements' => $commonRequirements,
        ]);
    }

    #[Route('/framework/{id}/assess', name: 'app_compliance_assess', requirements: ['id' => '\d+'])]
    public function assessFramework(int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        // Run assessment and update all requirement fulfillment percentages
        $assessmentResults = $this->assessmentService->assessFramework($framework);

        $this->addFlash('success', sprintf(
            'Assessment completed for %s. %d requirements assessed.',
            $framework->getName(),
            $assessmentResults['requirements_assessed']
        ));

        return $this->redirectToRoute('app_compliance_framework', ['id' => $id]);
    }

    #[Route('/frameworks/manage', name: 'app_compliance_manage_frameworks')]
    public function manageFrameworks(): Response
    {
        $availableFrameworks = $this->frameworkLoaderService->getAvailableFrameworks();
        $statistics = $this->frameworkLoaderService->getFrameworkStatistics();

        return $this->render('compliance/manage_frameworks.html.twig', [
            'available_frameworks' => $availableFrameworks,
            'statistics' => $statistics,
        ]);
    }

    #[Route('/frameworks/load/{code}', name: 'app_compliance_load_framework', methods: ['POST'])]
    public function loadFramework(string $code): JsonResponse
    {
        $result = $this->frameworkLoaderService->loadFramework($code);

        if ($result['success']) {
            $this->addFlash('success', $result['message']);
        } else {
            $this->addFlash('error', $result['message']);
        }

        return new JsonResponse($result);
    }

    #[Route('/frameworks/available', name: 'app_compliance_available_frameworks', methods: ['GET'])]
    public function getAvailableFrameworks(): JsonResponse
    {
        $frameworks = $this->frameworkLoaderService->getAvailableFrameworks();
        $statistics = $this->frameworkLoaderService->getFrameworkStatistics();

        return new JsonResponse([
            'frameworks' => $frameworks,
            'statistics' => $statistics,
        ]);
    }

    #[Route('/export/transitive', name: 'app_compliance_export_transitive')]
    public function exportTransitive(): Response
    {
        $this->addFlash('info', 'Transitive compliance export feature coming soon.');
        return $this->redirectToRoute('app_compliance_transitive');
    }

    #[Route('/export/comparison', name: 'app_compliance_export_comparison')]
    public function exportComparison(): Response
    {
        $this->addFlash('info', 'Framework comparison export feature coming soon.');
        return $this->redirectToRoute('app_compliance_compare');
    }
}
