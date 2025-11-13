<?php

namespace App\Controller;

use App\Entity\ComplianceMapping;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ComplianceMappingRepository;
use App\Service\ComplianceAssessmentService;
use App\Service\ComplianceMappingService;
use App\Service\ComplianceFrameworkLoaderService;
use App\Service\ExcelExportService;
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
        private CsrfTokenManagerInterface $csrfTokenManager,
        private ExcelExportService $excelExportService
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

    #[Route('/framework/{id}/data-reuse/export', name: 'app_compliance_export_reuse', requirements: ['id' => '\d+'])]
    public function exportDataReuse(int $id): Response
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

        // Create CSV content
        $csv = [];

        // CSV Header - Title
        $csv[] = ['Data Reuse Insights - ' . $framework->getName()];
        $csv[] = [];

        // Summary section
        $csv[] = ['Zusammenfassung'];
        $csv[] = ['Framework', $framework->getName() . ' (' . $framework->getCode() . ')'];
        $csv[] = ['Gesamt Zeitersparnis (Stunden)', $totalTimeSavings];
        $csv[] = ['Gesamt Zeitersparnis (Tage)', round($totalTimeSavings / 8, 1)];
        $csv[] = ['Anzahl analysierten Anforderungen', count($dataReuseAnalysis)];
        $csv[] = [];

        // CSV Header - Requirements
        $csv[] = [
            'Anforderungs-ID',
            'Titel',
            'Kategorie',
            'Wiederverwendbare Daten',
            'Datenquelle',
            'Geschätzte Zeitersparnis (h)',
            'Reuse-Prozentsatz (%)',
            'Confidence',
        ];

        // CSV Data - Requirements
        foreach ($dataReuseAnalysis as $item) {
            $requirement = $item['requirement'];
            $value = $item['value'];
            $analysis = $item['analysis'];

            $reusableDataSources = [];
            if (!empty($analysis['reusable_data'])) {
                foreach ($analysis['reusable_data'] as $data) {
                    $reusableDataSources[] = $data['source'] ?? 'Unknown';
                }
            }

            $csv[] = [
                $requirement->getRequirementId(),
                $requirement->getTitle(),
                $requirement->getCategory() ?? '-',
                !empty($analysis['reusable_data']) ? count($analysis['reusable_data']) : 0,
                !empty($reusableDataSources) ? implode(', ', $reusableDataSources) : '-',
                $value['estimated_hours_saved'] ?? 0,
                $value['reuse_percentage'] ?? 0,
                $value['confidence'] ?? 'low',
            ];
        }

        // Generate CSV file
        $filename = sprintf(
            'data_reuse_insights_%s_%s.csv',
            $framework->getCode(),
            date('Y-m-d_His')
        );

        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        // Add BOM for Excel UTF-8 support
        $csvContent = "\xEF\xBB\xBF";

        // Create CSV content
        $handle = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($handle, $row, ';');
        }
        rewind($handle);
        $csvContent .= stream_get_contents($handle);
        fclose($handle);

        $response->setContent($csvContent);

        return $response;
    }

    #[Route('/framework/{id}/gaps/export', name: 'app_compliance_export_gaps', requirements: ['id' => '\d+'])]
    public function exportGaps(int $id): Response
    {
        $framework = $this->frameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $gaps = $this->requirementRepository->findGapsByFramework($framework);
        $requirements = $this->requirementRepository->findByFramework($framework);
        $metRequirements = count($requirements) - count($gaps);

        // Analyze each gap
        $gapAnalysis = [];
        foreach ($gaps as $gap) {
            $analysis = $this->assessmentService->assessRequirement($gap);
            $gapAnalysis[] = [
                'requirement' => $gap,
                'analysis' => $analysis,
            ];
        }

        // Create CSV content
        $csv = [];

        // CSV Header - Title
        $csv[] = ['Gap Analysis - ' . $framework->getName()];
        $csv[] = [];

        // Summary section
        $csv[] = ['Zusammenfassung'];
        $csv[] = ['Framework', $framework->getName() . ' (' . $framework->getCode() . ')'];
        $csv[] = ['Gesamt Anforderungen', count($requirements)];
        $csv[] = ['Erfüllte Anforderungen', $metRequirements];
        $csv[] = ['Identifizierte Gaps', count($gaps)];
        $complianceScore = count($requirements) > 0 ? round(($metRequirements / count($requirements)) * 100, 2) : 0;
        $csv[] = ['Compliance Score (%)', $complianceScore];
        $csv[] = [];

        // Gap severity breakdown
        $criticalCount = 0;
        $highCount = 0;
        $mediumCount = 0;
        $lowCount = 0;

        foreach ($gapAnalysis as $item) {
            $priority = $item['requirement']->getPriority() ?? 'low';
            switch ($priority) {
                case 'critical':
                    $criticalCount++;
                    break;
                case 'high':
                    $highCount++;
                    break;
                case 'medium':
                    $mediumCount++;
                    break;
                default:
                    $lowCount++;
            }
        }

        $csv[] = ['Gaps nach Severity'];
        $csv[] = ['Kritisch', $criticalCount];
        $csv[] = ['Hoch', $highCount];
        $csv[] = ['Mittel', $mediumCount];
        $csv[] = ['Niedrig', $lowCount];
        $csv[] = [];

        // CSV Header - Gaps
        $csv[] = [
            'Anforderungs-ID',
            'Titel',
            'Kategorie',
            'Beschreibung',
            'Priority/Severity',
            'Status',
            'Erfüllungsgrad (%)',
            'Gap-Grund',
        ];

        // CSV Data - Gaps
        foreach ($gapAnalysis as $item) {
            $requirement = $item['requirement'];
            $analysis = $item['analysis'];

            // Translate priority
            $priorityMap = [
                'critical' => 'Kritisch',
                'high' => 'Hoch',
                'medium' => 'Mittel',
                'low' => 'Niedrig',
            ];

            // Translate status
            $statusMap = [
                'not_applicable' => 'Nicht anwendbar',
                'not_implemented' => 'Nicht implementiert',
                'partially_implemented' => 'Teilweise implementiert',
                'implemented' => 'Implementiert',
                'not_assessed' => 'Nicht bewertet',
            ];

            $csv[] = [
                $requirement->getRequirementId(),
                $requirement->getTitle(),
                $requirement->getCategory() ?? '-',
                $requirement->getDescription() ?? '-',
                $priorityMap[$requirement->getPriority() ?? 'low'] ?? 'Niedrig',
                $statusMap[$requirement->getStatus() ?? 'not_assessed'] ?? 'Nicht bewertet',
                $requirement->getFulfillmentPercentage() ?? 0,
                $analysis['gap_reason'] ?? 'Nicht erfüllt',
            ];
        }

        // Generate CSV file
        $filename = sprintf(
            'gap_analysis_%s_%s.csv',
            $framework->getCode(),
            date('Y-m-d_His')
        );

        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        // Add BOM for Excel UTF-8 support
        $csvContent = "\xEF\xBB\xBF";

        // Create CSV content
        $handle = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($handle, $row, ';');
        }
        rewind($handle);
        $csvContent .= stream_get_contents($handle);
        fclose($handle);

        $response->setContent($csvContent);

        return $response;
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
        $frameworks = $this->frameworkRepository->findActiveFrameworks();
        $transitiveAnalysis = [];
        $frameworkRelationships = [];

        // Build transitive analysis data (same as in transitiveCompliance method)
        foreach ($frameworks as $sourceFramework) {
            foreach ($frameworks as $targetFramework) {
                if ($sourceFramework->getId() === $targetFramework->getId()) {
                    continue;
                }

                // Calculate coverage
                $coverage = $this->mappingRepository->calculateFrameworkCoverage(
                    $sourceFramework,
                    $targetFramework
                );

                // Get transitive analysis
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
                    $frameworkRelationships[] = [
                        'sourceFramework' => $sourceFramework,
                        'targetFramework' => $targetFramework,
                        'mappedRequirements' => $coverage['covered_requirements'] ?? 0,
                        'totalRequirements' => $coverage['total_requirements'] ?? 0,
                        'coveragePercentage' => round($coverage['coverage_percentage'] ?? 0, 2),
                    ];
                }
            }
        }

        // Create CSV content
        $csv = [];

        // CSV Header - Framework Relationships
        $csv[] = ['Framework-Beziehungen und Transitive Compliance'];
        $csv[] = [];
        $csv[] = [
            'Quell-Framework',
            'Ziel-Framework',
            'Gemappte Anforderungen',
            'Gesamt-Anforderungen',
            'Coverage (%)',
        ];

        // CSV Data - Framework Relationships
        foreach ($frameworkRelationships as $relationship) {
            $csv[] = [
                $relationship['sourceFramework']->getName() . ' (' . $relationship['sourceFramework']->getCode() . ')',
                $relationship['targetFramework']->getName() . ' (' . $relationship['targetFramework']->getCode() . ')',
                $relationship['mappedRequirements'],
                $relationship['totalRequirements'],
                $relationship['coveragePercentage'],
            ];
        }

        // Add summary section
        $csv[] = [];
        $csv[] = ['Zusammenfassung'];
        $csv[] = [];
        $csv[] = ['Metrik', 'Wert'];
        $csv[] = ['Anzahl aktiver Frameworks', count($frameworks)];
        $csv[] = ['Anzahl Framework-Beziehungen', count($frameworkRelationships)];
        $csv[] = ['Transitive Compliance Opportunities', count($transitiveAnalysis)];

        if (!empty($transitiveAnalysis)) {
            $totalRequirementsHelped = array_sum(array_column($transitiveAnalysis, 'requirements_helped'));
            $csv[] = ['Gesamt unterstützte Anforderungen', $totalRequirementsHelped];
        }

        // Generate CSV file
        $filename = sprintf(
            'transitive_compliance_export_%s.csv',
            date('Y-m-d_His')
        );

        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        // Add BOM for Excel UTF-8 support
        $csvContent = "\xEF\xBB\xBF";

        // Create CSV content
        $handle = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($handle, $row, ';'); // Use semicolon as delimiter for Excel compatibility
        }
        rewind($handle);
        $csvContent .= stream_get_contents($handle);
        fclose($handle);

        $response->setContent($csvContent);

        return $response;
    }

    #[Route('/export/comparison', name: 'app_compliance_export_comparison')]
    public function exportComparison(Request $request): Response
    {
        $framework1Id = $request->query->get('framework1');
        $framework2Id = $request->query->get('framework2');

        if (!$framework1Id || !$framework2Id) {
            $this->addFlash('error', 'Bitte wählen Sie zwei Frameworks zum Vergleich aus.');
            return $this->redirectToRoute('app_compliance_compare');
        }

        $framework1 = $this->frameworkRepository->find($framework1Id);
        $framework2 = $this->frameworkRepository->find($framework2Id);

        if (!$framework1 || !$framework2) {
            $this->addFlash('error', 'Ein oder beide Frameworks wurden nicht gefunden.');
            return $this->redirectToRoute('app_compliance_compare');
        }

        // Build detailed comparison data
        $comparisonDetails = [];

        foreach ($framework1->getRequirements() as $req1) {
            $mappedRequirement = null;
            $matchQuality = null;
            $isMapped = false;

            // Find mappings where req1 is the source
            $sourceMappings = $this->mappingRepository->findBy([
                'sourceRequirement' => $req1
            ]);

            foreach ($sourceMappings as $mapping) {
                if ($mapping->getTargetRequirement()->getFramework()->getId() === $framework2->getId()) {
                    $mappedRequirement = $mapping->getTargetRequirement();
                    $matchQuality = $mapping->getMappingPercentage();
                    $isMapped = true;
                    break;
                }
            }

            // Also check reverse mappings where req1 is the target
            if (!$isMapped) {
                $targetMappings = $this->mappingRepository->findBy([
                    'targetRequirement' => $req1
                ]);

                foreach ($targetMappings as $mapping) {
                    if ($mapping->getSourceRequirement()->getFramework()->getId() === $framework2->getId()) {
                        $mappedRequirement = $mapping->getSourceRequirement();
                        $matchQuality = $mapping->getMappingPercentage();
                        $isMapped = true;
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

        // Create CSV content
        $csv = [];

        // CSV Header
        $csv[] = [
            $framework1->getName() . ' - ID',
            $framework1->getName() . ' - Titel',
            $framework1->getName() . ' - Kategorie',
            'Mapping Status',
            'Match Qualität (%)',
            $framework2->getName() . ' - ID',
            $framework2->getName() . ' - Titel',
            $framework2->getName() . ' - Kategorie',
        ];

        // CSV Data
        foreach ($comparisonDetails as $detail) {
            $csv[] = [
                $detail['framework1Requirement']->getRequirementId(),
                $detail['framework1Requirement']->getTitle(),
                $detail['framework1Requirement']->getCategory() ?? '-',
                $detail['mapped'] ? 'Gemapped' : 'Nicht gemapped',
                $detail['matchQuality'] ?? '-',
                $detail['framework2Requirement'] ? $detail['framework2Requirement']->getRequirementId() : '-',
                $detail['framework2Requirement'] ? $detail['framework2Requirement']->getTitle() : '-',
                $detail['framework2Requirement'] ? ($detail['framework2Requirement']->getCategory() ?? '-') : '-',
            ];
        }

        // Generate CSV file
        $filename = sprintf(
            'framework_comparison_%s_vs_%s_%s.csv',
            $framework1->getCode(),
            $framework2->getCode(),
            date('Y-m-d')
        );

        $response = new Response();
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

        // Add BOM for Excel UTF-8 support
        $csvContent = "\xEF\xBB\xBF";

        // Create CSV content
        $handle = fopen('php://temp', 'r+');
        foreach ($csv as $row) {
            fputcsv($handle, $row, ';'); // Use semicolon as delimiter for Excel compatibility
        }
        rewind($handle);
        $csvContent .= stream_get_contents($handle);
        fclose($handle);

        $response->setContent($csvContent);

        return $response;
    }

    #[Route('/export/comparison/excel', name: 'app_compliance_export_comparison_excel')]
    public function exportComparisonExcel(Request $request): Response
    {
        $framework1Id = $request->query->get('framework1');
        $framework2Id = $request->query->get('framework2');

        if (!$framework1Id || !$framework2Id) {
            $this->addFlash('error', 'Bitte wählen Sie zwei Frameworks zum Vergleich aus.');
            return $this->redirectToRoute('app_compliance_compare');
        }

        $framework1 = $this->frameworkRepository->find($framework1Id);
        $framework2 = $this->frameworkRepository->find($framework2Id);

        if (!$framework1 || !$framework2) {
            $this->addFlash('error', 'Ein oder beide Frameworks wurden nicht gefunden.');
            return $this->redirectToRoute('app_compliance_compare');
        }

        // Build detailed comparison data
        $comparisonDetails = [];
        $mappedCount = 0;

        foreach ($framework1->getRequirements() as $req1) {
            $mappedRequirement = null;
            $matchQuality = null;
            $isMapped = false;

            // Find mappings
            $sourceMappings = $this->mappingRepository->findBy(['sourceRequirement' => $req1]);
            foreach ($sourceMappings as $mapping) {
                if ($mapping->getTargetRequirement()->getFramework()->getId() === $framework2->getId()) {
                    $mappedRequirement = $mapping->getTargetRequirement();
                    $matchQuality = $mapping->getMappingPercentage();
                    $isMapped = true;
                    $mappedCount++;
                    break;
                }
            }

            if (!$isMapped) {
                $targetMappings = $this->mappingRepository->findBy(['targetRequirement' => $req1]);
                foreach ($targetMappings as $mapping) {
                    if ($mapping->getSourceRequirement()->getFramework()->getId() === $framework2->getId()) {
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

        // Create spreadsheet
        $spreadsheet = $this->excelExportService->createSpreadsheet('Framework Comparison Report');

        // === TAB 1: Summary ===
        $summarySheet = $spreadsheet->getActiveSheet();
        $summarySheet->setTitle('Zusammenfassung');

        $framework1Count = count($framework1->getRequirements());
        $framework2Count = count($framework2->getRequirements());
        $overlapPercentage = $framework1Count > 0 ? round(($mappedCount / $framework1Count) * 100, 1) : 0;

        $metrics = [
            'Framework 1' => $framework1->getName() . ' (' . $framework1->getCode() . ')',
            'Framework 2' => $framework2->getName() . ' (' . $framework2->getCode() . ')',
            'Framework 1 Anforderungen' => $framework1Count,
            'Framework 2 Anforderungen' => $framework2Count,
            'Gemappte Anforderungen' => $mappedCount,
            'Overlap Prozentsatz' => $overlapPercentage . '%',
            'Export-Datum' => date('d.m.Y H:i'),
        ];

        $this->excelExportService->addSummarySection($summarySheet, $metrics, 1, 'Framework Vergleich');
        $this->excelExportService->autoSizeColumns($summarySheet);

        // === TAB 2: Detailed Comparison ===
        $detailsSheet = $this->excelExportService->createSheet($spreadsheet, 'Detaillierter Vergleich');

        $headers = [
            $framework1->getName() . ' ID',
            $framework1->getName() . ' Titel',
            $framework1->getName() . ' Kategorie',
            'Mapping Status',
            'Match %',
            $framework2->getName() . ' ID',
            $framework2->getName() . ' Titel',
            $framework2->getName() . ' Kategorie',
        ];

        $this->excelExportService->addFormattedHeaderRow($detailsSheet, $headers, 1, true);

        $data = [];
        foreach ($comparisonDetails as $detail) {
            $data[] = [
                $detail['framework1Requirement']->getRequirementId(),
                $detail['framework1Requirement']->getTitle(),
                $detail['framework1Requirement']->getCategory() ?? '-',
                $detail['mapped'] ? 'Gemapped' : 'Nicht gemapped',
                $detail['matchQuality'] ?? '-',
                $detail['framework2Requirement'] ? $detail['framework2Requirement']->getRequirementId() : '-',
                $detail['framework2Requirement'] ? $detail['framework2Requirement']->getTitle() : '-',
                $detail['framework2Requirement'] ? ($detail['framework2Requirement']->getCategory() ?? '-') : '-',
            ];
        }

        // Conditional formatting for mapping status and match quality
        $conditionalFormatting = [
            3 => [ // Mapping Status
                'Gemapped' => $this->excelExportService->getColor('success'),
                'Nicht gemapped' => ['color' => $this->excelExportService->getColor('warning'), 'bold' => false],
            ],
            4 => [ // Match Quality
                '>=80' => $this->excelExportService->getColor('success'),
                '>=60' => $this->excelExportService->getColor('warning'),
                '<60' => $this->excelExportService->getColor('danger'),
            ],
        ];

        $this->excelExportService->addFormattedDataRows($detailsSheet, $data, 2, $conditionalFormatting);
        $this->excelExportService->autoSizeColumns($detailsSheet);

        // === TAB 3: Framework 1 Unique ===
        $framework1Unique = array_filter($comparisonDetails, fn($d) => !$d['mapped']);
        if (!empty($framework1Unique)) {
            $unique1Sheet = $this->excelExportService->createSheet($spreadsheet, 'Unique ' . substr($framework1->getCode(), 0, 10));

            $uniqueHeaders = ['ID', 'Titel', 'Kategorie', 'Beschreibung'];
            $this->excelExportService->addFormattedHeaderRow($unique1Sheet, $uniqueHeaders, 1, true);

            $uniqueData = [];
            foreach ($framework1Unique as $detail) {
                $req = $detail['framework1Requirement'];
                $uniqueData[] = [
                    $req->getRequirementId(),
                    $req->getTitle(),
                    $req->getCategory() ?? '-',
                    substr($req->getDescription() ?? '-', 0, 200), // Limit description length
                ];
            }

            $this->excelExportService->addFormattedDataRows($unique1Sheet, $uniqueData, 2);
            $this->excelExportService->autoSizeColumns($unique1Sheet);
        }

        // Generate Excel file
        $content = $this->excelExportService->generateExcel($spreadsheet);

        $filename = sprintf(
            'framework_comparison_%s_vs_%s_%s.xlsx',
            $framework1->getCode(),
            $framework2->getCode(),
            date('Y-m-d_His')
        );

        $response = new Response($content);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Content-Length', strlen($content));

        return $response;
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

                // Strategy 2: Direct mapping when one framework IS ISO 27001
                $isFramework1Iso = $framework1->getCode() === 'ISO27001';
                $isFramework2Iso = $framework2->getCode() === 'ISO27001';

                if ($isFramework1Iso || $isFramework2Iso) {
                    // Determine which is ISO and which has iso_controls
                    $isoFramework = $isFramework1Iso ? $framework1 : $framework2;
                    $otherFramework = $isFramework1Iso ? $framework2 : $framework1;
                    $isoRequirements = $isFramework1Iso ? $requirements1 : $requirements2;
                    $otherRequirements = $isFramework1Iso ? $requirements2 : $requirements1;

                    // Build map of other framework's requirements by ISO control
                    $otherByIsoControl = [];
                    foreach ($otherRequirements as $req) {
                        $dataSourceMapping = $req->getDataSourceMapping();
                        if (!empty($dataSourceMapping['iso_controls'])) {
                            $isoControls = is_array($dataSourceMapping['iso_controls'])
                                ? $dataSourceMapping['iso_controls']
                                : [$dataSourceMapping['iso_controls']];

                            foreach ($isoControls as $controlId) {
                                $normalizedId = 'A.' . str_replace(['A.', 'A'], '', $controlId);
                                if (!isset($otherByIsoControl[$normalizedId])) {
                                    $otherByIsoControl[$normalizedId] = [];
                                }
                                $otherByIsoControl[$normalizedId][] = $req;
                            }
                        }
                    }

                    // Map ISO requirements directly to other framework requirements
                    foreach ($isoRequirements as $isoReq) {
                        $isoControlId = $isoReq->getRequirementId(); // e.g., 'A.5.1'

                        if (isset($otherByIsoControl[$isoControlId])) {
                            $otherReqs = $otherByIsoControl[$isoControlId];

                            foreach ($otherReqs as $otherReq) {
                                $pairKey = $isoReq->getId() . '-' . $otherReq->getId();
                                $reversePairKey = $otherReq->getId() . '-' . $isoReq->getId();

                                if (!isset($createdPairs[$pairKey]) && !isset($createdPairs[$reversePairKey])
                                    && !isset($existingPairs[$pairKey]) && !isset($existingPairs[$reversePairKey])) {

                                    // Forward mapping: ISO → Other
                                    $mapping = new ComplianceMapping();
                                    $mapping->setSourceRequirement($isoReq)
                                        ->setTargetRequirement($otherReq)
                                        ->setMappingPercentage(90)
                                        ->setMappingType('partial')
                                        ->setBidirectional(true)
                                        ->setConfidence('high')
                                        ->setMappingRationale(sprintf(
                                            'Direct mapping: ISO 27001 %s to %s requirement',
                                            $isoControlId,
                                            $otherFramework->getName()
                                        ));

                                    $em->persist($mapping);
                                    $mappingsCreated++;
                                    $createdPairs[$pairKey] = true;

                                    // Reverse mapping: Other → ISO
                                    $reverseMapping = new ComplianceMapping();
                                    $reverseMapping->setSourceRequirement($otherReq)
                                        ->setTargetRequirement($isoReq)
                                        ->setMappingPercentage(90)
                                        ->setMappingType('partial')
                                        ->setBidirectional(true)
                                        ->setConfidence('high')
                                        ->setMappingRationale(sprintf(
                                            'Direct mapping: %s requirement to ISO 27001 %s',
                                            $otherFramework->getName(),
                                            $isoControlId
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
            // Get batch parameters for chunking
            $data = json_decode($request->getContent(), true) ?? [];
            $currentBatch = $data['batch'] ?? 0;
            $batchSize = $data['batch_size'] ?? 50; // Process 50 mappings per batch

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
            $potentialMappings = []; // Collect all potential mappings first

            // 1. Collect potential mappings FROM other frameworks TO ISO 27001
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
                            $reversePairKey = $isoRequirement->getId() . '-' . $requirement->getId();

                            if (!isset($existingPairs[$pairKey])) {
                                $potentialMappings[] = [
                                    'type' => 'forward',
                                    'source' => $requirement,
                                    'target' => $isoRequirement,
                                    'pairKey' => $pairKey,
                                    'framework' => $framework,
                                    'controlId' => $normalizedId
                                ];
                            }

                            if (!isset($existingPairs[$reversePairKey])) {
                                $potentialMappings[] = [
                                    'type' => 'reverse',
                                    'source' => $isoRequirement,
                                    'target' => $requirement,
                                    'pairKey' => $reversePairKey,
                                    'framework' => $framework,
                                    'controlId' => $normalizedId
                                ];
                            }
                        }
                    }
                }
            }

            // 2. Collect transitive mappings between non-ISO frameworks
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

                // Collect cross-mappings between all requirements that map to same ISO control
                for ($i = 0; $i < count($mappedToThisISO); $i++) {
                    for ($j = $i + 1; $j < count($mappedToThisISO); $j++) {
                        $req1 = $mappedToThisISO[$i];
                        $req2 = $mappedToThisISO[$j];

                        $pairKey = $req1->getId() . '-' . $req2->getId();
                        $reversePairKey = $req2->getId() . '-' . $req1->getId();

                        if (!isset($existingPairs[$pairKey]) && !isset($existingPairs[$reversePairKey])) {
                            $potentialMappings[] = [
                                'type' => 'transitive_forward',
                                'source' => $req1,
                                'target' => $req2,
                                'pairKey' => $pairKey,
                                'isoControl' => $isoReq->getRequirementId()
                            ];

                            $potentialMappings[] = [
                                'type' => 'transitive_reverse',
                                'source' => $req2,
                                'target' => $req1,
                                'pairKey' => $reversePairKey,
                                'isoControl' => $isoReq->getRequirementId()
                            ];
                        }
                    }
                }
            }

            // 3. Process mappings in batches to avoid timeouts
            $totalPotentialMappings = count($potentialMappings);
            $startIndex = $currentBatch * $batchSize;
            $endIndex = min($startIndex + $batchSize, $totalPotentialMappings);
            $hasMore = $endIndex < $totalPotentialMappings;

            // Process only the current batch
            for ($i = $startIndex; $i < $endIndex; $i++) {
                $mappingData = $potentialMappings[$i];

                // Skip if already created in this session
                if (isset($createdPairs[$mappingData['pairKey']])) {
                    $mappingsSkipped++;
                    continue;
                }

                $mapping = new ComplianceMapping();
                $mapping->setSourceRequirement($mappingData['source'])
                    ->setTargetRequirement($mappingData['target'])
                    ->setBidirectional(true);

                // Set type-specific properties
                if (str_starts_with($mappingData['type'], 'transitive')) {
                    $mapping->setMappingPercentage(75)
                        ->setMappingType('partial')
                        ->setConfidence('medium')
                        ->setMappingRationale(sprintf(
                            'Transitive mapping via ISO 27001 %s',
                            $mappingData['isoControl']
                        ));
                } else {
                    $mapping->setMappingPercentage(85)
                        ->setMappingType('partial')
                        ->setConfidence('high')
                        ->setMappingRationale(sprintf(
                            '%s requirement mapped to ISO 27001 %s',
                            $mappingData['framework']->getCode(),
                            $mappingData['controlId']
                        ));
                }

                $em->persist($mapping);
                $mappingsCreated++;
                $createdPairs[$mappingData['pairKey']] = true;
            }

            $em->flush();
            $em->clear(); // Clear entity manager to free memory

            // Debug info
            $frameworkCounts = [];
            foreach ($frameworks as $fw) {
                $reqCount = count($this->requirementRepository->findBy(['framework' => $fw]));
                $frameworkCounts[$fw->getCode()] = $reqCount;
            }

            $processedSoFar = min($endIndex, $totalPotentialMappings);
            $remaining = max(0, $totalPotentialMappings - $processedSoFar);
            $progressPercent = $totalPotentialMappings > 0
                ? round(($processedSoFar / $totalPotentialMappings) * 100, 1)
                : 100;

            $message = sprintf(
                'Batch %d: %d Mappings erstellt',
                $currentBatch + 1,
                $mappingsCreated
            );
            if ($hasMore) {
                $message .= sprintf(' (%d%% - %d/%d verarbeitet, %d verbleibend)',
                    $progressPercent,
                    $processedSoFar,
                    $totalPotentialMappings,
                    $remaining
                );
            } else {
                $message .= ' - Alle Mappings erstellt!';
            }

            // Additional debug info to help diagnose mapping issues
            $debugInfo = [
                'frameworks_loaded' => count($frameworks),
                'framework_details' => $frameworkCounts,
                'batch_info' => [
                    'current_batch' => $currentBatch,
                    'batch_size' => $batchSize,
                    'total_potential_mappings' => $totalPotentialMappings,
                    'processed_so_far' => $processedSoFar,
                    'remaining' => $remaining,
                    'progress_percent' => $progressPercent,
                    'has_more' => $hasMore,
                    'next_batch' => $hasMore ? $currentBatch + 1 : null,
                ],
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
                'has_more' => $hasMore,
                'next_batch' => $hasMore ? $currentBatch + 1 : null,
                'progress' => [
                    'total' => $totalPotentialMappings,
                    'processed' => $processedSoFar,
                    'remaining' => $remaining,
                    'percent' => $progressPercent,
                ],
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
