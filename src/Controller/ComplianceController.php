<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\LocalizedFlashTrait;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ComplianceMappingRepository;
use App\Service\ComplianceAssessmentService;
use App\Service\ComplianceMappingService;
use App\Service\ComplianceRequirementFulfillmentService;
use App\Service\FrameworkMaturityService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * ComplianceController
 *
 * Dashboard and view routes for compliance management:
 * - Compliance overview / index
 * - Framework dashboard
 * - Gap analysis view
 * - Data-reuse insights view
 * - Cross-framework mappings view
 * - Transitive compliance view
 * - Framework comparison view
 * - Framework assessment (POST action)
 * - Framework management redirect
 *
 * Export routes live in ComplianceExportController.
 * Mapping admin/creation routes live in ComplianceMappingAdminController.
 */
class ComplianceController extends AbstractController
{
    use LocalizedFlashTrait;

    public function __construct(
        private readonly ComplianceFrameworkRepository $complianceFrameworkRepository,
        private readonly ComplianceRequirementRepository $complianceRequirementRepository,
        private readonly ComplianceMappingRepository $complianceMappingRepository,
        private readonly ComplianceAssessmentService $complianceAssessmentService,
        private readonly ComplianceMappingService $complianceMappingService,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ModuleConfigurationService $moduleConfigurationService,
        private readonly ComplianceRequirementFulfillmentService $complianceRequirementFulfillmentService,
        private readonly TenantContext $tenantContext,
        private readonly ?FrameworkMaturityService $frameworkMaturityService = null,
        private readonly ?TranslatorInterface $translator = null,
    ) {}

    protected function getFlashDomain(): string
    {
        return 'compliance';
    }

    protected function getTranslator(): TranslatorInterface
    {
        if ($this->translator === null) {
            // @intentional-assertion: nullable-translator fallback exists only for test ctor compat
            throw new \RuntimeException('TranslatorInterface not injected — flash methods unavailable.');
        }
        return $this->translator;
    }

    #[Route('/compliance', name: 'app_compliance_index', methods: ['GET'])]
    public function index(): Response
    {
        $frameworks = $this->complianceFrameworkRepository->findActiveFrameworks();
        $overview = $this->complianceFrameworkRepository->getComplianceOverview();
        $mappingStats = $this->complianceMappingRepository->getMappingStatistics();

        // Calculate total data reuse value
        $totalTimeSavings = 0;
        foreach ($frameworks as $framework) {
            $requirements = $this->complianceRequirementRepository->findApplicableByFramework($framework);
            foreach ($requirements as $requirement) {
                $reuseValue = $this->complianceMappingService->calculateDataReuseValue($requirement);
                $totalTimeSavings += $reuseValue['estimated_hours_saved'];
            }
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $maturityPortfolio = $this->frameworkMaturityService?->computePortfolio($tenant) ?? [];

        return $this->render('compliance/index.html.twig', [
            'frameworks' => $frameworks,
            'overview' => $overview,
            'mapping_stats' => $mappingStats,
            'total_time_savings' => $totalTimeSavings,
            'total_days_savings' => round($totalTimeSavings / 8, 1),
            'maturity_portfolio' => $maturityPortfolio,
        ]);
    }

    #[Route('/compliance/framework/{id}', name: 'app_compliance_framework', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function frameworkDashboard(int $id): Response
    {
        $framework = $this->complianceFrameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $dashboard = $this->complianceAssessmentService->getComplianceDashboard($framework);
        $requirements = $this->complianceRequirementRepository->findByFramework($framework);

        $allModules = $this->moduleConfigurationService->getAllModules();
        $activeModules = $this->moduleConfigurationService->getActiveModules();

        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('No tenant assigned to user. Please contact administrator.');
        }

        // Load tenant-specific fulfillments for all requirements (batch)
        // Including detailed requirements (nested)
        // For SUPER_ADMIN without tenant, show empty fulfillments
        $fulfillments = [];
        if ($tenant instanceof Tenant) {
            foreach ($requirements as $requirement) {
                $fulfillment = $this->complianceRequirementFulfillmentService->getOrCreateFulfillment($tenant, $requirement);
                $fulfillments[$requirement->getId()] = $fulfillment;

                // Also load fulfillments for detailed requirements
                if ($requirement->hasDetailedRequirements()) {
                    foreach ($requirement->getDetailedRequirements() as $detailedRequirement) {
                        $detailedFulfillment = $this->complianceRequirementFulfillmentService->getOrCreateFulfillment($tenant, $detailedRequirement);
                        $fulfillments[$detailedRequirement->getId()] = $detailedFulfillment;
                    }
                }
            }
        }

        return $this->render('compliance/framework_dashboard.html.twig', [
            'framework' => $framework,
            'dashboard' => $dashboard,
            'requirements' => $requirements,
            'fulfillments' => $fulfillments,
            'all_modules' => $allModules,
            'active_modules' => $activeModules,
        ]);
    }

    #[Route('/compliance/framework/{id}/gaps', name: 'app_compliance_gaps', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function gapAnalysis(int $id): Response
    {
        $framework = $this->complianceFrameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $gaps = $this->complianceRequirementRepository->findGapsByFramework($framework, 75, $tenant);
        $criticalGaps = $this->complianceRequirementRepository->findByFrameworkAndPriority($framework, 'critical');

        // Analyze each gap for detailed insights
        $gapAnalysis = [];
        foreach ($gaps as $gap) {
            $analysis = $this->complianceAssessmentService->assessRequirement($gap);
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

    #[Route('/compliance/framework/{id}/data-reuse', name: 'app_compliance_data_reuse', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function dataReuseInsights(int $id): Response
    {
        $framework = $this->complianceFrameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        $requirements = $this->complianceRequirementRepository->findApplicableByFramework($framework);
        $dataReuseAnalysis = [];
        $totalTimeSavings = 0;

        foreach ($requirements as $requirement) {
            $analysis = $this->complianceMappingService->getDataReuseAnalysis($requirement);
            $reuseValue = $this->complianceMappingService->calculateDataReuseValue($requirement);

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

    #[Route('/compliance/cross-framework', name: 'app_compliance_cross_framework', methods: ['GET'])]
    public function crossFrameworkMappings(): Response
    {
        $frameworks = $this->complianceFrameworkRepository->findActiveFrameworks();
        $crossMappings = [];
        $coverageMatrix = [];

        // Generate cross-framework coverage matrix
        foreach ($frameworks as $framework) {
            foreach ($frameworks as $targetFramework) {
                if ($framework->id === $targetFramework->id) {
                    continue;
                }

                $coverage = $this->complianceMappingRepository->calculateFrameworkCoverage(
                    $framework,
                    $targetFramework
                );

                $coverageMatrix[$framework->getCode()][$targetFramework->getCode()] = $coverage;

                // Get detailed mappings
                $mappings = $this->complianceMappingRepository->findCrossFrameworkMappings(
                    $framework,
                    $targetFramework
                );

                if ($mappings !== []) {
                    $crossMappings[] = [
                        'source' => $framework,
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

    #[Route('/compliance/transitive-compliance', name: 'app_compliance_transitive', methods: ['GET'])]
    public function transitiveCompliance(): Response
    {
        $frameworks = $this->complianceFrameworkRepository->findActiveFrameworks();

        // perf: single bulk query replaces O(N²) per-pair queries (was >10 s with many frameworks)
        $allMappingsBulk = $this->complianceMappingRepository->findAllCrossFrameworkMappingsBulk($frameworks);

        $transitiveAnalysis = [];
        $mappingMatrix = [];
        $crossMappings = [];
        $coverageMatrix = [];
        $frameworkRelationships = [];
        $frameworksLeveragedSet = [];

        foreach ($frameworks as $framework) {
            foreach ($frameworks as $targetFramework) {
                if ($framework->id === $targetFramework->id) {
                    continue;
                }

                // Use pre-loaded bulk data — no per-pair DB query
                $pairMappings = $allMappingsBulk[$framework->getId()][$targetFramework->getId()] ?? [];

                // Inline coverage calculation (was calculateFrameworkCoverage — now uses cached data)
                $targetRequirements = $targetFramework->requirements->count();
                $coveredRequirements = [];
                foreach ($pairMappings as $mapping) {
                    $targetReqId = $mapping->getTargetRequirement()->getId();
                    $pct = $mapping->getMappingPercentage();
                    if (!isset($coveredRequirements[$targetReqId]) || $coveredRequirements[$targetReqId] < $pct) {
                        $coveredRequirements[$targetReqId] = $pct;
                    }
                }
                $totalCoveragePct = array_sum(array_map(static fn(int $c): int => min(100, $c), $coveredRequirements));
                $avgCoverage = $targetRequirements > 0 ? round($totalCoveragePct / $targetRequirements, 2) : 0;

                $coverage = [
                    'source_framework'       => $framework->getName(),
                    'target_framework'       => $targetFramework->getName(),
                    'total_target_requirements' => $targetRequirements,
                    'covered_requirements'   => count($coveredRequirements),
                    'coverage_percentage'    => $avgCoverage,
                    'strong_mappings'        => count(array_filter($coveredRequirements, fn(int $c): bool => $c >= 100)),
                    'partial_mappings'       => count(array_filter($coveredRequirements, fn(int $c): bool => $c >= 50 && $c < 100)),
                    'weak_mappings'          => count(array_filter($coveredRequirements, fn(int $c): bool => $c < 50)),
                ];

                $mappingMatrix[$framework->id][$targetFramework->id] = [
                    'coverage' => $avgCoverage,
                    'has_mapping' => $avgCoverage > 0,
                ];

                $coverageMatrix[$framework->getCode()][$targetFramework->getCode()] = $coverage;

                // Inline transitive calculation (was getTransitiveCompliance — now uses cached data)
                $targetRequirementsHelped = [];
                foreach ($pairMappings as $mapping) {
                    $transitiveFulfillment = $mapping->calculateTransitiveFulfillment();
                    if ($transitiveFulfillment > 0) {
                        $targetReqId = $mapping->getTargetRequirement()->getRequirementId();
                        if (!isset($targetRequirementsHelped[$targetReqId])
                            || $targetRequirementsHelped[$targetReqId] < $transitiveFulfillment) {
                            $targetRequirementsHelped[$targetReqId] = $transitiveFulfillment;
                        }
                    }
                }
                $totalBenefit = array_sum($targetRequirementsHelped);
                $targetReqCount = $targetFramework->requirements->count();
                $avgBenefit = $targetReqCount > 0 ? round($totalBenefit / $targetReqCount, 2) : 0;

                if (count($targetRequirementsHelped) > 0) {
                    $transitiveAnalysis[] = [
                        'source_framework'          => $framework->getName(),
                        'target_framework'          => $targetFramework->getName(),
                        'requirements_helped'       => count($targetRequirementsHelped),
                        'average_transitive_benefit' => $avgBenefit,
                        'total_benefit'             => round($totalBenefit, 2),
                    ];
                }

                if ($pairMappings !== [] && $avgCoverage > 0) {
                    $crossMappings[] = [
                        'source'   => $framework,
                        'target'   => $targetFramework,
                        'mappings' => $pairMappings,
                        'coverage' => $coverage,
                    ];

                    $frameworkRelationships[] = (object)[
                        'id'                  => $framework->id . '_' . $targetFramework->id,
                        'sourceFramework'     => $framework,
                        'targetFramework'     => $targetFramework,
                        'mappedRequirements'  => $coverage['covered_requirements'],
                        'coveragePercentage'  => round($avgCoverage),
                    ];

                    $frameworksLeveragedSet[$targetFramework->id] = true;
                }
            }
        }

        return $this->render('compliance/transitive_compliance.html.twig', [
            'frameworks'           => $frameworks,
            'transitive_analysis'  => $transitiveAnalysis,
            'mapping_matrix'       => $mappingMatrix,
            'total_relationships'  => count($frameworkRelationships),
            'transitive_compliance' => array_sum(array_column($transitiveAnalysis, 'requirements_helped')),
            'leverage_opportunities' => [],
            'cross_mappings'       => $crossMappings,
            'coverage_matrix'      => $coverageMatrix,
            'framework_relationships' => $frameworkRelationships,
            'frameworks_leveraged' => count($frameworksLeveragedSet),
        ]);
    }

    #[Route('/compliance/compare', name: 'app_compliance_compare', methods: ['GET'])]
    public function compareFrameworks(Request $request): Response
    {
        $frameworks = $this->complianceFrameworkRepository->findActiveFrameworks();

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
            $selectedFramework1 = $this->complianceFrameworkRepository->find($framework1Id);
            $selectedFramework2 = $this->complianceFrameworkRepository->find($framework2Id);

            if ($selectedFramework1 && $selectedFramework2) {
                $comparison = $this->complianceAssessmentService->compareFrameworks([$selectedFramework1, $selectedFramework2]);
                $framework1Requirements = count($selectedFramework1->requirements);
                $framework2Requirements = count($selectedFramework2->requirements);

                // Get unique categories from each framework
                $framework1Categories = array_unique(
                    array_filter(
                        array_map(fn(ComplianceRequirement $req): ?string => $req->getCategory(), $selectedFramework1->requirements->toArray())
                    )
                );
                $framework2Categories = array_unique(
                    array_filter(
                        array_map(fn(ComplianceRequirement $req): ?string => $req->getCategory(), $selectedFramework2->requirements->toArray())
                    )
                );

                // Build detailed comparison data
                $comparisonDetails = [];
                $mappedCount = 0;

                foreach ($selectedFramework1->requirements as $requirement) {
                    $mappedRequirement = null;
                    $matchQuality = null;
                    $isMapped = false;

                    // Find mappings where req1 is the source
                    $sourceMappings = $this->complianceMappingRepository->findBy([
                        'sourceRequirement' => $requirement
                    ]);

                    foreach ($sourceMappings as $sourceMapping) {
                        if ($sourceMapping->getTargetRequirement()->getFramework()->id === $selectedFramework2->id) {
                            $mappedRequirement = $sourceMapping->getTargetRequirement();
                            $matchQuality = $sourceMapping->getMappingPercentage();
                            $isMapped = true;
                            $mappedCount++;
                            break;
                        }
                    }

                    // Also check reverse mappings where req1 is the target
                    if (!$isMapped) {
                        $targetMappings = $this->complianceMappingRepository->findBy([
                            'targetRequirement' => $requirement
                        ]);

                        foreach ($targetMappings as $targetMapping) {
                            if ($targetMapping->getSourceRequirement()->getFramework()->id === $selectedFramework2->id) {
                                $mappedRequirement = $targetMapping->getSourceRequirement();
                                $matchQuality = $targetMapping->getMappingPercentage();
                                $isMapped = true;
                                $mappedCount++;
                                break;
                            }
                        }
                    }

                    $comparisonDetails[] = [
                        'framework1Requirement' => $requirement,
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
                foreach ($comparisonDetails as $comparisonDetail) {
                    if ($comparisonDetail['mapped'] && $comparisonDetail['framework2Requirement'] instanceof ComplianceRequirement) {
                        $mappedFramework2Ids[] = $comparisonDetail['framework2Requirement']->getId();
                    }
                }

                foreach ($selectedFramework2->requirements as $req2) {
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

    #[Route('/compliance/framework/{id}/assess', name: 'app_compliance_assess', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function assessFramework(int $id): Response
    {
        $framework = $this->complianceFrameworkRepository->find($id);

        if (!$framework) {
            throw $this->createNotFoundException('Framework not found');
        }

        // Run assessment and update all requirement fulfillment percentages
        $assessmentResults = $this->complianceAssessmentService->assessFramework($framework);

        $this->addFlash('success', sprintf(
            'Assessment completed for %s. %d requirements assessed.',
            $framework->getName(),
            $assessmentResults['requirements_assessed']
        ));

        return $this->redirectToRoute('app_compliance_framework', ['id' => $id]);
    }

    /**
     * Redirect to admin framework management
     * Framework management is now centralized in the admin panel
     */
    #[Route('/compliance/frameworks/manage', name: 'app_compliance_manage_frameworks', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function manageFrameworks(): Response
    {
        // Redirect to centralized admin framework management
        return $this->redirectToRoute('admin_compliance_index');
    }
}
