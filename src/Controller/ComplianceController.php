<?php

namespace App\Controller;

use App\Entity\ComplianceMapping;
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
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

#[Route('/compliance')]
class ComplianceController extends AbstractController
{
    public function __construct(
        private ComplianceFrameworkRepository $frameworkRepository,
        private ComplianceRequirementRepository $requirementRepository,
        private ComplianceMappingRepository $mappingRepository,
        private ComplianceAssessmentService $assessmentService,
        private ComplianceMappingService $mappingService,
        private ComplianceFrameworkLoaderService $frameworkLoaderService,
        private CsrfTokenManagerInterface $csrfTokenManager
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
        $crossMappings = [];
        $coverageMatrix = [];
        $frameworkRelationships = [];
        $frameworksLeveragedSet = [];

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
                    'coverage' => $coverage['coverage_percentage'] ?? 0,
                    'has_mapping' => ($coverage['coverage_percentage'] ?? 0) > 0
                ];

                // Build coverage matrix for cross-framework display
                $coverageMatrix[$sourceFramework->getCode()][$targetFramework->getCode()] = $coverage;

                // Transitive analysis
                $transitive = $this->mappingRepository->getTransitiveCompliance(
                    $sourceFramework,
                    $targetFramework
                );

                if ($transitive['requirements_helped'] > 0) {
                    $transitiveAnalysis[] = $transitive;
                }

                // Get detailed cross-framework mappings
                $mappings = $this->mappingRepository->findCrossFrameworkMappings(
                    $sourceFramework,
                    $targetFramework
                );

                if (!empty($mappings) && ($coverage['coverage_percentage'] ?? 0) > 0) {
                    $crossMappings[] = [
                        'source' => $sourceFramework,
                        'target' => $targetFramework,
                        'mappings' => $mappings,
                        'coverage' => $coverage,
                    ];

                    // Build framework relationships for KPI cards
                    $frameworkRelationships[] = (object)[
                        'id' => $sourceFramework->getId() . '_' . $targetFramework->getId(),
                        'sourceFramework' => $sourceFramework,
                        'targetFramework' => $targetFramework,
                        'mappedRequirements' => $coverage['covered_requirements'] ?? 0,
                        'coveragePercentage' => round($coverage['coverage_percentage'] ?? 0),
                    ];

                    // Track frameworks being leveraged
                    $frameworksLeveragedSet[$targetFramework->getId()] = true;
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
            'cross_mappings' => $crossMappings,
            'coverage_matrix' => $coverageMatrix,
            'framework_relationships' => $frameworkRelationships,
            'frameworks_leveraged' => count($frameworksLeveragedSet),
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
        $framework1Categories = [];
        $framework2Categories = [];

        $framework1Id = $request->query->get('framework1');
        $framework2Id = $request->query->get('framework2');

        if ($framework1Id && $framework2Id) {
            $selectedFramework1 = $this->frameworkRepository->find($framework1Id);
            $selectedFramework2 = $this->frameworkRepository->find($framework2Id);

            if ($selectedFramework1 && $selectedFramework2) {
                $comparison = $this->assessmentService->compareFrameworks([$selectedFramework1, $selectedFramework2]);
                $framework1Requirements = count($selectedFramework1->getRequirements());
                $framework2Requirements = count($selectedFramework2->getRequirements());

                // Get unique categories from each framework
                $framework1Categories = array_unique(
                    array_filter(
                        array_map(fn($req) => $req->getCategory(), $selectedFramework1->getRequirements()->toArray())
                    )
                );
                $framework2Categories = array_unique(
                    array_filter(
                        array_map(fn($req) => $req->getCategory(), $selectedFramework2->getRequirements()->toArray())
                    )
                );

                // Build detailed comparison data
                $comparisonDetails = [];
                $mappedCount = 0;

                foreach ($selectedFramework1->getRequirements() as $req1) {
                    $mappedRequirement = null;
                    $matchQuality = null;
                    $isMapped = false;

                    // Find mappings where req1 is the source
                    $sourceMappings = $this->mappingRepository->findBy([
                        'sourceRequirement' => $req1
                    ]);

                    foreach ($sourceMappings as $mapping) {
                        if ($mapping->getTargetRequirement()->getFramework()->getId() === $selectedFramework2->getId()) {
                            $mappedRequirement = $mapping->getTargetRequirement();
                            $matchQuality = $mapping->getMappingPercentage();
                            $isMapped = true;
                            $mappedCount++;
                            break;
                        }
                    }

                    // Also check reverse mappings where req1 is the target
                    if (!$isMapped) {
                        $targetMappings = $this->mappingRepository->findBy([
                            'targetRequirement' => $req1
                        ]);

                        foreach ($targetMappings as $mapping) {
                            if ($mapping->getSourceRequirement()->getFramework()->getId() === $selectedFramework2->getId()) {
                                $mappedRequirement = $mapping->getSourceRequirement();
                                $matchQuality = $mapping->getMappingPercentage();
                                $isMapped = true;
                                $mappedCount++;
                                break;
                            }
                        }
                    }

                    $comparisonDetails[] = [
                        'framework1Requirement' => $req1,
                        'mapped' => $isMapped,
                        'framework2Requirement' => $mappedRequirement,
                        'matchQuality' => $matchQuality,
                    ];
                }

                $commonRequirements = $mappedCount;

                // Calculate unique requirements (not mapped)
                $framework1Unique = [];
                $framework2Unique = [];

                // Framework 1 unique requirements
                foreach ($comparisonDetails as $detail) {
                    if (!$detail['mapped']) {
                        $framework1Unique[] = $detail['framework1Requirement'];
                    }
                }

                // Framework 2 unique requirements
                $mappedFramework2Ids = [];
                foreach ($comparisonDetails as $detail) {
                    if ($detail['mapped'] && $detail['framework2Requirement']) {
                        $mappedFramework2Ids[] = $detail['framework2Requirement']->getId();
                    }
                }

                foreach ($selectedFramework2->getRequirements() as $req2) {
                    if (!in_array($req2->getId(), $mappedFramework2Ids)) {
                        $framework2Unique[] = $req2;
                    }
                }
            }
        }

        return $this->render('compliance/compare.html.twig', [
            'frameworks' => $frameworks,
            'selectedFramework1' => $selectedFramework1,
            'selectedFramework2' => $selectedFramework2,
            'comparison' => $comparison,
            'comparisonDetails' => $comparisonDetails ?? [],
            'framework1Requirements' => $framework1Requirements,
            'framework2Requirements' => $framework2Requirements,
            'commonRequirements' => $commonRequirements,
            'framework1UniqueRequirements' => max(0, $framework1Requirements - $commonRequirements),
            'framework2UniqueRequirements' => max(0, $framework2Requirements - $commonRequirements),
            'framework1Categories' => $framework1Categories,
            'framework2Categories' => $framework2Categories,
            'framework1Unique' => $framework1Unique ?? [],
            'framework2Unique' => $framework2Unique ?? [],
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

    #[Route('/frameworks/delete/{code}', name: 'app_compliance_delete_framework', methods: ['POST'])]
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
                    'message' => 'Framework nicht gefunden!'
                ], 404);
            }

            $frameworkName = $framework->getName();

            // Get all requirements for this framework
            $requirements = $this->requirementRepository->findBy(['framework' => $framework]);

            $deletedMappings = 0;
            $deletedRequirements = 0;
            $deletedMappingIds = []; // Track deleted mapping IDs to avoid duplicates

            // Delete all mappings associated with these requirements
            foreach ($requirements as $requirement) {
                // Find mappings where this requirement is the source
                $sourceMappings = $this->mappingRepository->findBy(['sourceRequirement' => $requirement]);
                foreach ($sourceMappings as $mapping) {
                    $mappingId = $mapping->getId();
                    if (!isset($deletedMappingIds[$mappingId])) {
                        $em->remove($mapping);
                        $deletedMappings++;
                        $deletedMappingIds[$mappingId] = true;
                    }
                }

                // Find mappings where this requirement is the target
                $targetMappings = $this->mappingRepository->findBy(['targetRequirement' => $requirement]);
                foreach ($targetMappings as $mapping) {
                    $mappingId = $mapping->getId();
                    if (!isset($deletedMappingIds[$mappingId])) {
                        $em->remove($mapping);
                        $deletedMappings++;
                        $deletedMappingIds[$mappingId] = true;
                    }
                }

                // Delete the requirement
                $em->remove($requirement);
                $deletedRequirements++;
            }

            // Delete the framework itself
            $em->remove($framework);

            // Flush all changes
            $em->flush();

            $this->addFlash('success', sprintf(
                'Framework "%s" wurde erfolgreich gelöscht (%d Anforderungen, %d Mappings).',
                $frameworkName,
                $deletedRequirements,
                $deletedMappings
            ));

            return new JsonResponse([
                'success' => true,
                'message' => sprintf(
                    'Framework "%s" wurde erfolgreich gelöscht (%d Anforderungen, %d Mappings).',
                    $frameworkName,
                    $deletedRequirements,
                    $deletedMappings
                ),
                'deleted_requirements' => $deletedRequirements,
                'deleted_mappings' => $deletedMappings,
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Fehler beim Löschen des Frameworks: ' . $e->getMessage()
            ], 500);
        }
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

    #[Route('/frameworks/create-comparison-mappings', name: 'app_compliance_create_comparison_mappings', methods: ['POST'])]
    public function createComparisonMappings(Request $request): JsonResponse
    {
        // Validate CSRF token
        $token = $request->headers->get('X-CSRF-Token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('create_mappings', $token))) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid CSRF token'
            ], 403);
        }

        try {
            $data = json_decode($request->getContent(), true);
            $framework1Id = $data['framework1_id'] ?? null;
            $framework2Id = $data['framework2_id'] ?? null;

            if (!$framework1Id || !$framework2Id) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Beide Framework IDs müssen angegeben werden!'
                ], 400);
            }

            if ($framework1Id === $framework2Id) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Die beiden Frameworks müssen unterschiedlich sein!'
                ], 400);
            }

            $em = $this->frameworkRepository->getEntityManager();

            // Load frameworks
            $framework1 = $this->frameworkRepository->find($framework1Id);
            $framework2 = $this->frameworkRepository->find($framework2Id);

            if (!$framework1 || !$framework2) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Ein oder beide Frameworks wurden nicht gefunden!'
                ], 404);
            }

            // Check if ISO 27001 exists (needed for transitive mappings)
            $iso27001 = $this->frameworkRepository->findOneBy(['code' => 'ISO27001']);

            // Load existing mappings to avoid duplicates (incremental approach)
            $existingMappings = $this->mappingRepository->findAll();
            $existingPairs = [];
            foreach ($existingMappings as $mapping) {
                $sourceId = $mapping->getSourceRequirement()->getId();
                $targetId = $mapping->getTargetRequirement()->getId();
                $existingPairs[$sourceId . '-' . $targetId] = true;
            }

            $mappingsCreated = 0;
            $mappingsSkipped = 0;
            $createdPairs = [];

            // Get requirements for both frameworks
            $requirements1 = $this->requirementRepository->findBy(['framework' => $framework1]);
            $requirements2 = $this->requirementRepository->findBy(['framework' => $framework2]);

            // Strategy 1: Direct mapping via ISO controls if available
            if ($iso27001) {
                // Build a map of ISO control IDs to requirements for both frameworks
                $framework1IsoMap = [];
                $framework2IsoMap = [];

                foreach ($requirements1 as $req) {
                    $dataSourceMapping = $req->getDataSourceMapping();
                    if (!empty($dataSourceMapping['iso_controls'])) {
                        $isoControls = is_array($dataSourceMapping['iso_controls'])
                            ? $dataSourceMapping['iso_controls']
                            : [$dataSourceMapping['iso_controls']];

                        foreach ($isoControls as $controlId) {
                            $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $controlId);
                            if (!isset($framework1IsoMap[$normalizedId])) {
                                $framework1IsoMap[$normalizedId] = [];
                            }
                            $framework1IsoMap[$normalizedId][] = $req;
                        }
                    }
                }

                foreach ($requirements2 as $req) {
                    $dataSourceMapping = $req->getDataSourceMapping();
                    if (!empty($dataSourceMapping['iso_controls'])) {
                        $isoControls = is_array($dataSourceMapping['iso_controls'])
                            ? $dataSourceMapping['iso_controls']
                            : [$dataSourceMapping['iso_controls']];

                        foreach ($isoControls as $controlId) {
                            $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $controlId);
                            if (!isset($framework2IsoMap[$normalizedId])) {
                                $framework2IsoMap[$normalizedId] = [];
                            }
                            $framework2IsoMap[$normalizedId][] = $req;
                        }
                    }
                }

                // Create mappings for requirements sharing the same ISO control
                foreach ($framework1IsoMap as $isoControl => $reqs1) {
                    if (isset($framework2IsoMap[$isoControl])) {
                        $reqs2 = $framework2IsoMap[$isoControl];

                        foreach ($reqs1 as $req1) {
                            foreach ($reqs2 as $req2) {
                                $pairKey = $req1->getId() . '-' . $req2->getId();
                                $reversePairKey = $req2->getId() . '-' . $req1->getId();

                                if (!isset($createdPairs[$pairKey]) && !isset($createdPairs[$reversePairKey])
                                    && !isset($existingPairs[$pairKey]) && !isset($existingPairs[$reversePairKey])) {

                                    // Forward mapping
                                    $mapping = new ComplianceMapping();
                                    $mapping->setSourceRequirement($req1)
                                        ->setTargetRequirement($req2)
                                        ->setMappingPercentage(80)
                                        ->setMappingType('partial')
                                        ->setBidirectional(true)
                                        ->setConfidence('high')
                                        ->setMappingRationale(sprintf(
                                            'Mapped via shared ISO 27001 control %s',
                                            $isoControl
                                        ));

                                    $em->persist($mapping);
                                    $mappingsCreated++;
                                    $createdPairs[$pairKey] = true;

                                    // Reverse mapping
                                    $reverseMapping = new ComplianceMapping();
                                    $reverseMapping->setSourceRequirement($req2)
                                        ->setTargetRequirement($req1)
                                        ->setMappingPercentage(80)
                                        ->setMappingType('partial')
                                        ->setBidirectional(true)
                                        ->setConfidence('high')
                                        ->setMappingRationale(sprintf(
                                            'Mapped via shared ISO 27001 control %s',
                                            $isoControl
                                        ));

                                    $em->persist($reverseMapping);
                                    $mappingsCreated++;
                                    $createdPairs[$reversePairKey] = true;
                                } elseif (isset($existingPairs[$pairKey]) || isset($existingPairs[$reversePairKey])) {
                                    $mappingsSkipped += 2;
                                }
                            }
                        }
                    }
                }
            }

            $em->flush();

            $message = sprintf(
                'Erfolgreich %d neue Mappings zwischen %s und %s erstellt!',
                $mappingsCreated,
                $framework1->getName(),
                $framework2->getName()
            );
            if ($mappingsSkipped > 0) {
                $message .= sprintf(' (%d bereits vorhanden, übersprungen)', $mappingsSkipped);
            }

            return new JsonResponse([
                'success' => true,
                'message' => $message,
                'mappings_created' => $mappingsCreated,
                'mappings_skipped' => $mappingsSkipped,
                'framework1' => $framework1->getName(),
                'framework2' => $framework2->getName(),
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Fehler beim Erstellen der Mappings: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/frameworks/create-mappings', name: 'app_compliance_create_mappings', methods: ['POST'])]
    public function createCrossFrameworkMappings(Request $request): JsonResponse
    {
        // Validate CSRF token
        $token = $request->headers->get('X-CSRF-Token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('create_mappings', $token))) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Invalid CSRF token'
            ], 403);
        }

        try {
            $em = $this->frameworkRepository->getEntityManager();

            // Check if ISO 27001 exists
            $iso27001 = $this->frameworkRepository->findOneBy(['code' => 'ISO27001']);
            if (!$iso27001) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'ISO 27001 Framework muss zuerst geladen werden!'
                ]);
            }

            // Get all frameworks
            $frameworks = $this->frameworkRepository->findAll();
            if (count($frameworks) < 2) {
                return new JsonResponse([
                    'success' => false,
                    'message' => 'Mindestens 2 Frameworks müssen geladen sein!'
                ]);
            }

            // Load existing mappings to avoid duplicates (incremental approach)
            $existingMappings = $this->mappingRepository->findAll();
            $existingPairs = [];
            foreach ($existingMappings as $mapping) {
                $sourceId = $mapping->getSourceRequirement()->getId();
                $targetId = $mapping->getTargetRequirement()->getId();
                $existingPairs[$sourceId . '-' . $targetId] = true;
            }

            $mappingsCreated = 0;
            $mappingsSkipped = 0;
            $createdPairs = []; // Track created mapping pairs to avoid duplicates

            // 1. Create mappings FROM other frameworks TO ISO 27001
            foreach ($frameworks as $framework) {
                if ($framework->getCode() === 'ISO27001') {
                    continue;
                }

                $requirements = $this->requirementRepository->findBy(['framework' => $framework]);

                foreach ($requirements as $requirement) {
                    $dataSourceMapping = $requirement->getDataSourceMapping();
                    if (empty($dataSourceMapping) || empty($dataSourceMapping['iso_controls'])) {
                        continue;
                    }

                    $isoControls = $dataSourceMapping['iso_controls'];
                    if (!is_array($isoControls)) {
                        $isoControls = [$isoControls];
                    }

                    foreach ($isoControls as $controlId) {
                        $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $controlId);

                        $isoRequirement = $this->requirementRepository->findOneBy([
                            'framework' => $iso27001,
                            'requirementId' => $normalizedId
                        ]);

                        if ($isoRequirement) {
                            $pairKey = $requirement->getId() . '-' . $isoRequirement->getId();

                            if (!isset($createdPairs[$pairKey]) && !isset($existingPairs[$pairKey])) {
                                // Forward mapping: Other → ISO
                                $mapping = new ComplianceMapping();
                                $mapping->setSourceRequirement($requirement)
                                    ->setTargetRequirement($isoRequirement)
                                    ->setMappingPercentage(85)
                                    ->setMappingType('partial')
                                    ->setBidirectional(true)
                                    ->setConfidence('high')
                                    ->setMappingRationale(sprintf(
                                        '%s requirement mapped to ISO 27001 %s',
                                        $framework->getCode(),
                                        $normalizedId
                                    ));

                                $em->persist($mapping);
                                $mappingsCreated++;
                                $createdPairs[$pairKey] = true;
                            } elseif (isset($existingPairs[$pairKey])) {
                                $mappingsSkipped++;
                            }

                            // Reverse mapping: ISO → Other
                            $reversePairKey = $isoRequirement->getId() . '-' . $requirement->getId();
                            if (!isset($createdPairs[$reversePairKey]) && !isset($existingPairs[$reversePairKey])) {
                                $reverseMapping = new ComplianceMapping();
                                $reverseMapping->setSourceRequirement($isoRequirement)
                                    ->setTargetRequirement($requirement)
                                    ->setMappingPercentage(85)
                                    ->setMappingType('partial')
                                    ->setBidirectional(true)
                                    ->setConfidence('high')
                                    ->setMappingRationale(sprintf(
                                        'ISO 27001 %s mapped to %s requirement',
                                        $normalizedId,
                                        $framework->getCode()
                                    ));

                                $em->persist($reverseMapping);
                                $mappingsCreated++;
                                $createdPairs[$reversePairKey] = true;
                            } elseif (isset($existingPairs[$reversePairKey])) {
                                $mappingsSkipped++;
                            }
                        }
                    }
                }
            }

            // 2. Create transitive mappings between non-ISO frameworks
            // If Framework A → ISO Control X and Framework B → ISO Control X, then A ↔ B
            $isoRequirements = $this->requirementRepository->findBy(['framework' => $iso27001]);

            foreach ($isoRequirements as $isoReq) {
                // Find all frameworks that map to this ISO requirement
                $mappedToThisISO = [];

                foreach ($frameworks as $framework) {
                    if ($framework->getCode() === 'ISO27001') {
                        continue;
                    }

                    $requirements = $this->requirementRepository->findBy(['framework' => $framework]);

                    foreach ($requirements as $req) {
                        $dataSourceMapping = $req->getDataSourceMapping();
                        if (empty($dataSourceMapping) || empty($dataSourceMapping['iso_controls'])) {
                            continue;
                        }

                        $isoControls = $dataSourceMapping['iso_controls'];
                        if (!is_array($isoControls)) {
                            $isoControls = [$isoControls];
                        }

                        foreach ($isoControls as $controlId) {
                            $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $controlId);

                            if ($normalizedId === $isoReq->getRequirementId()) {
                                $mappedToThisISO[] = $req;
                            }
                        }
                    }
                }

                // Create cross-mappings between all requirements that map to same ISO control
                for ($i = 0; $i < count($mappedToThisISO); $i++) {
                    for ($j = $i + 1; $j < count($mappedToThisISO); $j++) {
                        $req1 = $mappedToThisISO[$i];
                        $req2 = $mappedToThisISO[$j];

                        $pairKey = $req1->getId() . '-' . $req2->getId();
                        $reversePairKey = $req2->getId() . '-' . $req1->getId();

                        // Check if mappings already exist in database or were just created
                        if (!isset($createdPairs[$pairKey]) && !isset($createdPairs[$reversePairKey])
                            && !isset($existingPairs[$pairKey]) && !isset($existingPairs[$reversePairKey])) {

                            // Forward mapping
                            $crossMapping = new ComplianceMapping();
                            $crossMapping->setSourceRequirement($req1)
                                ->setTargetRequirement($req2)
                                ->setMappingPercentage(75)
                                ->setMappingType('partial')
                                ->setBidirectional(true)
                                ->setConfidence('medium')
                                ->setMappingRationale(sprintf(
                                    'Transitive mapping via ISO 27001 %s',
                                    $isoReq->getRequirementId()
                                ));

                            $em->persist($crossMapping);
                            $mappingsCreated++;
                            $createdPairs[$pairKey] = true;

                            // Reverse mapping
                            $reverseCrossMapping = new ComplianceMapping();
                            $reverseCrossMapping->setSourceRequirement($req2)
                                ->setTargetRequirement($req1)
                                ->setMappingPercentage(75)
                                ->setMappingType('partial')
                                ->setBidirectional(true)
                                ->setConfidence('medium')
                                ->setMappingRationale(sprintf(
                                    'Transitive mapping via ISO 27001 %s',
                                    $isoReq->getRequirementId()
                                ));

                            $em->persist($reverseCrossMapping);
                            $mappingsCreated++;
                            $createdPairs[$reversePairKey] = true;
                        } elseif (isset($existingPairs[$pairKey]) || isset($existingPairs[$reversePairKey])) {
                            // Both directions count as one skipped pair
                            $mappingsSkipped += 2;
                        }
                    }
                }
            }

            $em->flush();

            // Debug info
            $frameworkCounts = [];
            foreach ($frameworks as $fw) {
                $reqCount = count($this->requirementRepository->findBy(['framework' => $fw]));
                $frameworkCounts[$fw->getCode()] = $reqCount;
            }

            $message = sprintf(
                'Erfolgreich %d neue Cross-Framework Mappings erstellt!',
                $mappingsCreated
            );
            if ($mappingsSkipped > 0) {
                $message .= sprintf(' (%d bereits vorhanden, übersprungen)', $mappingsSkipped);
            }

            // Additional debug info to help diagnose mapping issues
            $debugInfo = [
                'frameworks_loaded' => count($frameworks),
                'framework_details' => $frameworkCounts,
            ];

            // Check if TISAX exists and has ISO controls
            $tisax = $this->frameworkRepository->findOneBy(['code' => 'TISAX']);
            if ($tisax) {
                $tisaxReqs = $this->requirementRepository->findBy(['framework' => $tisax]);
                $tisaxWithIsoControls = 0;
                $sampleIsoControls = [];

                foreach ($tisaxReqs as $req) {
                    $dataSourceMapping = $req->getDataSourceMapping();
                    if (!empty($dataSourceMapping['iso_controls'])) {
                        $tisaxWithIsoControls++;
                        if (count($sampleIsoControls) < 5) {
                            $sampleIsoControls[] = [
                                'requirement_id' => $req->getRequirementId(),
                                'iso_controls' => $dataSourceMapping['iso_controls']
                            ];
                        }
                    }
                }

                $debugInfo['tisax'] = [
                    'total_requirements' => count($tisaxReqs),
                    'with_iso_controls' => $tisaxWithIsoControls,
                    'sample_iso_controls' => $sampleIsoControls,
                ];
            }

            return new JsonResponse([
                'success' => true,
                'message' => $message,
                'mappings_created' => $mappingsCreated,
                'mappings_skipped' => $mappingsSkipped,
                'mappings_total' => $mappingsCreated + $mappingsSkipped,
                'debug' => $debugInfo
            ]);

        } catch (\Exception $e) {
            return new JsonResponse([
                'success' => false,
                'message' => 'Fehler beim Erstellen der Mappings: ' . $e->getMessage()
            ], 500);
        }
    }
}
