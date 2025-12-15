<?php

namespace App\Controller;

use DateTime;
use App\Repository\AssetRepository;
use App\Repository\BCExerciseRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ControlRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Repository\RiskTreatmentPlanRepository;
use App\Repository\SupplierRepository;
use App\Repository\TrainingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * DORA Compliance Dashboard Controller
 *
 * Provides real-time compliance monitoring for the Digital Operational Resilience Act (EU 2022/2554)
 *
 * DORA's 5 Pillars:
 * 1. ICT Risk Management (Articles 5-16)
 * 2. ICT Incident Management (Articles 17-23)
 * 3. Digital Operational Resilience Testing (Articles 24-27)
 * 4. ICT Third-Party Risk Management (Articles 28-44)
 * 5. Information Sharing (Articles 45)
 *
 * Note: This dashboard is only available when the DORA framework is installed and active.
 * DORA is mandatory for financial entities (banks, insurance, investment firms, etc.)
 */
#[IsGranted('ROLE_MANAGER')]
class DoraComplianceController extends AbstractController
{
    public function __construct(
        private readonly ComplianceFrameworkRepository $complianceFrameworkRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly RiskRepository $riskRepository,
        private readonly AssetRepository $assetRepository,
        private readonly ControlRepository $controlRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly BCExerciseRepository $bcExerciseRepository,
        private readonly RiskTreatmentPlanRepository $treatmentPlanRepository,
        private readonly TrainingRepository $trainingRepository,
        private readonly TranslatorInterface $translator
    ) {
    }

    #[Route('/dora-compliance', name: 'app_dora_compliance_dashboard')]
    public function dashboard(): Response
    {
        // Check if DORA framework exists and is active
        $doraFramework = $this->complianceFrameworkRepository->findOneBy(['code' => 'DORA']);

        if (!$doraFramework) {
            $this->addFlash('info', $this->translator->trans(
                'dora.not_installed',
                [],
                'dora'
            ));

            return $this->redirectToRoute('app_compliance_index');
        }

        if (!$doraFramework->isActive()) {
            $this->addFlash('warning', $this->translator->trans(
                'dora.not_active',
                [],
                'dora'
            ));

            return $this->redirectToRoute('app_compliance_index');
        }

        // === PILLAR 1: ICT Risk Management (Art. 5-16) ===
        $ictRiskMetrics = $this->getIctRiskManagementMetrics();

        // === PILLAR 2: ICT Incident Management (Art. 17-23) ===
        $incidentMetrics = $this->getIctIncidentManagementMetrics();

        // === PILLAR 3: Digital Operational Resilience Testing (Art. 24-27) ===
        $testingMetrics = $this->getResilienceTestingMetrics();

        // === PILLAR 4: ICT Third-Party Risk Management (Art. 28-44) ===
        $thirdPartyMetrics = $this->getThirdPartyRiskMetrics();

        // === PILLAR 5: Information Sharing (Art. 45) ===
        $sharingMetrics = $this->getInformationSharingMetrics();

        // Calculate overall compliance score
        $overallScore = $this->calculateOverallComplianceScore(
            $ictRiskMetrics,
            $incidentMetrics,
            $testingMetrics,
            $thirdPartyMetrics
        );

        return $this->render('dora_compliance/dashboard.html.twig', [
            'framework' => $doraFramework,
            'ict_risk' => $ictRiskMetrics,
            'incidents' => $incidentMetrics,
            'testing' => $testingMetrics,
            'third_party' => $thirdPartyMetrics,
            'information_sharing' => $sharingMetrics,
            'overall_score' => $overallScore,
        ]);
    }

    /**
     * PILLAR 1: ICT Risk Management Metrics (Art. 5-16)
     */
    private function getIctRiskManagementMetrics(): array
    {
        // Get all risks
        $allRisks = $this->riskRepository->findAll();
        $totalRisks = count($allRisks);

        // ICT-related risks (filter by category or name containing ICT/IT/Cyber)
        $ictRisks = array_filter($allRisks, function ($risk) {
            $category = strtolower($risk->getCategory() ?? '');
            $name = strtolower($risk->getName() ?? '');
            $description = strtolower($risk->getDescription() ?? '');
            $keywords = ['ict', 'it ', 'cyber', 'digital', 'system', 'network', 'data', 'software', 'hardware'];

            foreach ($keywords as $keyword) {
                if (str_contains($category, $keyword) || str_contains($name, $keyword) || str_contains($description, $keyword)) {
                    return true;
                }
            }
            return false;
        });

        $totalIctRisks = count($ictRisks);
        $highIctRisks = count(array_filter($ictRisks, fn($r) => $r->getInherentRiskLevel() >= 12));
        $criticalIctRisks = count(array_filter($ictRisks, fn($r) => $r->getInherentRiskLevel() >= 16));
        $treatedIctRisks = count(array_filter($ictRisks, fn($r) => $r->getTreatmentStrategy() !== null && $r->getTreatmentStrategy() !== ''));
        $treatmentRate = $totalIctRisks > 0 ? round(($treatedIctRisks / $totalIctRisks) * 100) : 100;

        // ICT Assets inventory (Art. 8)
        $allAssets = $this->assetRepository->findActiveAssets();
        $ictAssets = array_filter($allAssets, function ($asset) {
            $type = strtolower($asset->getType() ?? '');
            $category = strtolower($asset->getCategory() ?? '');
            $keywords = ['hardware', 'software', 'network', 'server', 'database', 'application', 'system', 'ict'];

            foreach ($keywords as $keyword) {
                if (str_contains($type, $keyword) || str_contains($category, $keyword)) {
                    return true;
                }
            }
            return false;
        });
        $totalIctAssets = count($ictAssets);
        $classifiedIctAssets = count(array_filter($ictAssets, fn($a) => $a->getConfidentialityValue() > 0 || $a->getIntegrityValue() > 0 || $a->getAvailabilityValue() > 0
        ));
        $assetClassificationRate = $totalIctAssets > 0 ? round(($classifiedIctAssets / $totalIctAssets) * 100) : 100;

        // Critical business functions (Art. 8)
        $allProcesses = $this->businessProcessRepository->findAll();
        $criticalProcesses = array_filter($allProcesses, fn($p) => $p->getCriticality() === 'critical' || $p->getCriticality() === 'high');
        $processesWithRto = array_filter($criticalProcesses, fn($p) => $p->getRto() !== null);
        $rtoCoverage = count($criticalProcesses) > 0 ? round((count($processesWithRto) / count($criticalProcesses)) * 100) : 100;

        // Controls implementation (Art. 9)
        $ictControls = $this->controlRepository->findBy(['category' => ['access_control', 'cryptography', 'network_security', 'operations_security', 'system_acquisition']]);
        if (count($ictControls) === 0) {
            // Fallback: get all applicable controls
            $ictControls = $this->controlRepository->findApplicableControls();
        }
        $implementedControls = count(array_filter($ictControls, fn($c) => $c->getImplementationStatus() === 'implemented'));
        $controlImplementationRate = count($ictControls) > 0 ? round(($implementedControls / count($ictControls)) * 100) : 0;

        return [
            'total_risks' => $totalRisks,
            'ict_risks' => $totalIctRisks,
            'high_ict_risks' => $highIctRisks,
            'critical_ict_risks' => $criticalIctRisks,
            'treatment_rate' => $treatmentRate,
            'ict_assets' => $totalIctAssets,
            'asset_classification_rate' => $assetClassificationRate,
            'critical_processes' => count($criticalProcesses),
            'rto_coverage' => $rtoCoverage,
            'control_implementation_rate' => $controlImplementationRate,
            'score' => round(($treatmentRate + $assetClassificationRate + $rtoCoverage + $controlImplementationRate) / 4),
        ];
    }

    /**
     * PILLAR 2: ICT Incident Management Metrics (Art. 17-23)
     */
    private function getIctIncidentManagementMetrics(): array
    {
        $allIncidents = $this->incidentRepository->findAll();
        $thisYear = (new DateTime())->format('Y');

        // Filter for ICT-related incidents
        $ictIncidents = array_filter($allIncidents, function ($incident) {
            $category = strtolower($incident->getCategory() ?? '');
            $type = strtolower($incident->getType() ?? '');
            $keywords = ['ict', 'cyber', 'system', 'network', 'data', 'security', 'malware', 'breach', 'outage'];

            foreach ($keywords as $keyword) {
                if (str_contains($category, $keyword) || str_contains($type, $keyword)) {
                    return true;
                }
            }
            return true; // Default: include all security incidents
        });

        $totalIctIncidents = count($ictIncidents);

        // Major incidents (high/critical severity)
        $majorIncidents = array_filter($ictIncidents, fn($i) => in_array($i->getSeverity(), ['critical', 'high'], true));
        $totalMajorIncidents = count($majorIncidents);

        // Incidents this year
        $incidentsThisYear = array_filter($ictIncidents, fn($i) => $i->getDetectedAt() !== null && $i->getDetectedAt()->format('Y') === $thisYear
        );
        $majorIncidentsYtd = count(array_filter($incidentsThisYear, fn($i) => in_array($i->getSeverity(), ['critical', 'high'], true)));

        // Open incidents
        $openIncidents = array_filter($ictIncidents, fn($i) => $i->getStatus() === 'open' || $i->getStatus() === 'investigating');
        $openMajorIncidents = array_filter($majorIncidents, fn($i) => $i->getStatus() === 'open' || $i->getStatus() === 'investigating');

        // 4h initial notification compliance (Art. 19)
        $reportedIn4h = count(array_filter($majorIncidents, function ($i) {
            if ($i->getDetectedAt() === null) {
                return false;
            }
            // Check if reported within 4 hours (simplified check)
            $reportedAt = $i->getReportedAt() ?? $i->getCreatedAt();
            if ($reportedAt === null) {
                return false;
            }
            $diff = $i->getDetectedAt()->diff($reportedAt);
            return ($diff->days === 0 && $diff->h <= 4);
        }));
        $reportingComplianceRate = $totalMajorIncidents > 0 ? round(($reportedIn4h / $totalMajorIncidents) * 100) : 100;

        // Mean Time To Resolve (MTTR)
        $resolvedIncidents = array_filter($incidentsThisYear, fn($i) => $i->getResolvedAt() !== null);
        $mttrHours = 0;
        if (count($resolvedIncidents) > 0) {
            $totalHours = 0;
            foreach ($resolvedIncidents as $incident) {
                if ($incident->getDetectedAt() !== null) {
                    $diff = $incident->getDetectedAt()->diff($incident->getResolvedAt());
                    $totalHours += ($diff->days * 24) + $diff->h;
                }
            }
            $mttrHours = round($totalHours / count($resolvedIncidents));
        }

        // Incident classification compliance
        $classifiedIncidents = count(array_filter($ictIncidents, fn($i) => $i->getSeverity() !== null && $i->getSeverity() !== ''));
        $classificationRate = $totalIctIncidents > 0 ? round(($classifiedIncidents / $totalIctIncidents) * 100) : 100;

        return [
            'total_incidents' => $totalIctIncidents,
            'major_incidents' => $totalMajorIncidents,
            'major_incidents_ytd' => $majorIncidentsYtd,
            'open_incidents' => count($openIncidents),
            'open_major_incidents' => count($openMajorIncidents),
            'reporting_compliance_rate' => $reportingComplianceRate,
            'mttr_hours' => $mttrHours,
            'classification_rate' => $classificationRate,
            'score' => round(($reportingComplianceRate + $classificationRate + min(100, max(0, 100 - ($mttrHours / 24)))) / 3),
        ];
    }

    /**
     * PILLAR 3: Digital Operational Resilience Testing Metrics (Art. 24-27)
     */
    private function getResilienceTestingMetrics(): array
    {
        $thisYear = (new DateTime())->format('Y');

        // BC Exercises / Resilience Tests
        $allExercises = $this->bcExerciseRepository->findAll();
        $exercisesThisYear = array_filter($allExercises, fn($e) => $e->getScheduledDate() !== null && $e->getScheduledDate()->format('Y') === $thisYear
        );
        $completedExercises = array_filter($exercisesThisYear, fn($e) => $e->getStatus() === 'completed');

        // BC Plans coverage
        $allPlans = $this->bcPlanRepository->findAll();
        $activePlans = array_filter($allPlans, fn($p) => $p->getStatus() === 'approved' || $p->getStatus() === 'active');
        $testedPlans = array_filter($activePlans, fn($p) => method_exists($p, 'getLastTestDate') && $p->getLastTestDate() !== null
        );

        // Critical processes with BC plans
        $criticalProcesses = array_filter($this->businessProcessRepository->findAll(), fn($p) => $p->getCriticality() === 'critical');
        $processesWithBcPlan = array_filter($criticalProcesses, fn($p) => $p->getBusinessContinuityPlan() !== null);
        $bcPlanCoverage = count($criticalProcesses) > 0 ? round((count($processesWithBcPlan) / count($criticalProcesses)) * 100) : 100;

        // Training / awareness for resilience
        $allTrainings = $this->trainingRepository->findAll();
        $resilienceTrainings = array_filter($allTrainings, function ($t) {
            $topic = strtolower($t->getTopic() ?? '');
            $keywords = ['resilience', 'continuity', 'disaster', 'recovery', 'backup', 'incident'];

            foreach ($keywords as $keyword) {
                if (str_contains($topic, $keyword)) {
                    return true;
                }
            }
            return false;
        });

        // Minimum 1 exercise per year requirement
        $exerciseCompliance = count($completedExercises) >= 1 ? 100 : (count($exercisesThisYear) > 0 ? 50 : 0);

        return [
            'exercises_planned' => count($exercisesThisYear),
            'exercises_completed' => count($completedExercises),
            'active_bc_plans' => count($activePlans),
            'bc_plan_coverage' => $bcPlanCoverage,
            'resilience_trainings' => count($resilienceTrainings),
            'exercise_compliance' => $exerciseCompliance,
            'score' => round(($exerciseCompliance + $bcPlanCoverage) / 2),
        ];
    }

    /**
     * PILLAR 4: ICT Third-Party Risk Management Metrics (Art. 28-44)
     */
    private function getThirdPartyRiskMetrics(): array
    {
        $allSuppliers = $this->supplierRepository->findAll();

        // ICT third-party providers
        $ictProviders = array_filter($allSuppliers, function ($supplier) {
            $type = strtolower($supplier->getType() ?? '');
            $services = strtolower($supplier->getServicesProvided() ?? '');
            $keywords = ['ict', 'it ', 'cloud', 'software', 'hosting', 'data', 'network', 'saas', 'iaas', 'paas'];

            foreach ($keywords as $keyword) {
                if (str_contains($type, $keyword) || str_contains($services, $keyword)) {
                    return true;
                }
            }
            return false;
        });

        $totalIctProviders = count($ictProviders);

        // Critical ICT providers
        $criticalProviders = array_filter($ictProviders, fn($s) => $s->getCriticality() === 'critical' || $s->getCriticality() === 'high');
        $totalCriticalProviders = count($criticalProviders);

        // Assessed providers
        $assessedProviders = array_filter($criticalProviders, fn($s) => $s->getLastAssessmentDate() !== null);
        $assessmentRate = $totalCriticalProviders > 0 ? round((count($assessedProviders) / $totalCriticalProviders) * 100) : 100;

        // Overdue assessments (> 12 months)
        $overdueAssessments = count(array_filter($criticalProviders, fn($s) => $s->getLastAssessmentDate() === null || $s->getLastAssessmentDate()->diff(new DateTime())->days > 365
        ));

        // Concentration risk - providers with high dependency
        $highDependencyProviders = array_filter($ictProviders, fn($s) => $s->getDependencyLevel() === 'critical' || $s->getDependencyLevel() === 'high');

        // Exit strategies
        $providersWithExitStrategy = array_filter($criticalProviders, fn($s) => method_exists($s, 'getExitStrategy') && $s->getExitStrategy() !== null && $s->getExitStrategy() !== ''
        );
        $exitStrategyRate = $totalCriticalProviders > 0 ? round((count($providersWithExitStrategy) / $totalCriticalProviders) * 100) : 100;

        return [
            'total_ict_providers' => $totalIctProviders,
            'critical_providers' => $totalCriticalProviders,
            'assessment_rate' => $assessmentRate,
            'overdue_assessments' => $overdueAssessments,
            'high_dependency_providers' => count($highDependencyProviders),
            'exit_strategy_rate' => $exitStrategyRate,
            'score' => round(($assessmentRate + $exitStrategyRate) / 2),
        ];
    }

    /**
     * PILLAR 5: Information Sharing Metrics (Art. 45)
     */
    private function getInformationSharingMetrics(): array
    {
        // Information sharing is mostly organizational/process based
        // This can be tracked through documented processes and controls

        $infoSharingControls = $this->controlRepository->findBy(['category' => 'information_sharing']);
        if (count($infoSharingControls) === 0) {
            // Look for controls with information sharing in name
            $allControls = $this->controlRepository->findApplicableControls();
            $infoSharingControls = array_filter($allControls, function ($c) {
                $name = strtolower($c->getName() ?? '');
                return str_contains($name, 'information sharing') || str_contains($name, 'threat intelligence');
            });
        }

        $implemented = count(array_filter($infoSharingControls, fn($c) => $c->getImplementationStatus() === 'implemented'));
        $implementationRate = count($infoSharingControls) > 0 ? round(($implemented / count($infoSharingControls)) * 100) : 0;

        return [
            'controls_total' => count($infoSharingControls),
            'controls_implemented' => $implemented,
            'implementation_rate' => $implementationRate,
            'score' => $implementationRate,
        ];
    }

    /**
     * Calculate overall DORA compliance score
     */
    private function calculateOverallComplianceScore(array $ictRisk, array $incidents, array $testing, array $thirdParty): array
    {
        // Weight the pillars (ICT Risk and Third-Party are most critical under DORA)
        $weights = [
            'ict_risk' => 30,
            'incidents' => 25,
            'testing' => 20,
            'third_party' => 25,
        ];

        $weightedScore = (
            ($ictRisk['score'] * $weights['ict_risk']) +
            ($incidents['score'] * $weights['incidents']) +
            ($testing['score'] * $weights['testing']) +
            ($thirdParty['score'] * $weights['third_party'])
        ) / array_sum($weights);

        $status = match (true) {
            $weightedScore >= 90 => 'compliant',
            $weightedScore >= 70 => 'partial',
            $weightedScore >= 50 => 'in_progress',
            default => 'non_compliant',
        };

        return [
            'score' => round($weightedScore),
            'status' => $status,
            'pillar_scores' => [
                'ict_risk' => $ictRisk['score'],
                'incidents' => $incidents['score'],
                'testing' => $testing['score'],
                'third_party' => $thirdParty['score'],
            ],
        ];
    }
}
