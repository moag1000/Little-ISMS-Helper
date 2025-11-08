<?php

namespace App\Service;

use App\Repository\BCExerciseRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\ChangeRequestRepository;
use App\Repository\InterestedPartyRepository;
use App\Repository\RiskRepository;
use App\Repository\SupplierRepository;

/**
 * ISO Compliance Intelligence Service
 *
 * Provides intelligent insights for ISO 27001, ISO 22301, ISO 27005, and ISO 31000 compliance
 * using data reuse across all entities
 */
class ISOComplianceIntelligenceService
{
    public function __construct(
        private SupplierRepository $supplierRepository,
        private InterestedPartyRepository $interestedPartyRepository,
        private BusinessContinuityPlanRepository $bcPlanRepository,
        private BCExerciseRepository $bcExerciseRepository,
        private ChangeRequestRepository $changeRequestRepository,
        private RiskRepository $riskRepository
    ) {}

    /**
     * Get overall ISO compliance dashboard
     */
    public function getComplianceDashboard(): array
    {
        return [
            'iso27001' => $this->getISO27001Compliance(),
            'iso22301' => $this->getISO22301Compliance(),
            'iso27005' => $this->getISO27005Compliance(),
            'iso31000' => $this->getISO31000Compliance(),
            'overall_score' => $this->calculateOverallComplianceScore(),
            'critical_actions' => $this->getCriticalActions(),
            'recommendations' => $this->getRecommendations(),
        ];
    }

    /**
     * ISO 27001 Compliance Analysis
     */
    public function getISO27001Compliance(): array
    {
        $supplierStats = $this->supplierRepository->getStatistics();
        $interestedParties = $this->interestedPartyRepository->findAll();
        $overdueComms = $this->interestedPartyRepository->findOverdueCommunications();

        // Chapter 4.2: Understanding needs of interested parties
        $chapter42Compliance = count($interestedParties) > 0 ? 100 : 0;
        if (count($overdueComms) > 0) {
            $chapter42Compliance -= min(50, count($overdueComms) * 10);
        }

        // Annex A.15: Supplier relationships
        $supplierCompliance = 100;
        if ($supplierStats['total'] == 0) {
            $supplierCompliance = 0;
        } else {
            if ($supplierStats['overdue_assessments'] > 0) {
                $supplierCompliance -= min(40, $supplierStats['overdue_assessments'] * 10);
            }
            if ($supplierStats['non_compliant'] > 0) {
                $supplierCompliance -= min(30, $supplierStats['non_compliant'] * 10);
            }
        }

        $overallISO27001 = ($chapter42Compliance + $supplierCompliance) / 2;

        return [
            'score' => round($overallISO27001),
            'chapter_4_2' => round($chapter42Compliance),
            'annex_a_15' => round($supplierCompliance),
            'interested_parties_count' => count($interestedParties),
            'overdue_communications' => count($overdueComms),
            'supplier_compliance' => $supplierCompliance,
            'status' => $this->getComplianceStatus($overallISO27001),
        ];
    }

    /**
     * ISO 22301 Business Continuity Compliance
     */
    public function getISO22301Compliance(): array
    {
        $bcPlans = $this->bcPlanRepository->findAll();
        $activePlans = $this->bcPlanRepository->findActivePlans();
        $overdueTests = $this->bcPlanRepository->findOverdueTests();
        $overdueReviews = $this->bcPlanRepository->findOverdueReviews();
        $bcExerciseStats = $this->bcExerciseRepository->getStatistics();

        $score = 100;

        // BC Plans exist
        if (count($bcPlans) == 0) {
            $score = 20;
        } else {
            // Active plans
            if (count($activePlans) == 0) {
                $score -= 30;
            }

            // Overdue tests penalty
            if (count($overdueTests) > 0) {
                $score -= min(30, count($overdueTests) * 10);
            }

            // Overdue reviews penalty
            if (count($overdueReviews) > 0) {
                $score -= min(20, count($overdueReviews) * 10);
            }

            // BC exercises bonus
            if ($bcExerciseStats['total'] > 0) {
                $score += min(10, $bcExerciseStats['completed'] * 2);
            } else {
                $score -= 20;
            }
        }

        $score = max(0, min(100, $score));

        return [
            'score' => round($score),
            'bc_plans_total' => count($bcPlans),
            'active_plans' => count($activePlans),
            'overdue_tests' => count($overdueTests),
            'overdue_reviews' => count($overdueReviews),
            'exercises_completed' => $bcExerciseStats['completed'],
            'status' => $this->getComplianceStatus($score),
            'readiness_level' => $this->calculateBCReadiness($bcPlans),
        ];
    }

    /**
     * ISO 27005 Risk Management Compliance
     */
    public function getISO27005Compliance(): array
    {
        $risks = $this->riskRepository->findAll();
        $acceptedRisks = array_filter($risks, fn($r) => $r->getTreatmentStrategy() === 'accept');
        $pendingApproval = array_filter($acceptedRisks, fn($r) => $r->isAcceptanceApprovalRequired());

        $score = 100;

        if (count($risks) == 0) {
            $score = 30;
        } else {
            // Penalty for accepted risks without formal approval
            if (count($pendingApproval) > 0) {
                $penaltyPercentage = (count($pendingApproval) / count($risks)) * 50;
                $score -= $penaltyPercentage;
            }
        }

        return [
            'score' => round($score),
            'total_risks' => count($risks),
            'accepted_risks' => count($acceptedRisks),
            'pending_approval' => count($pendingApproval),
            'status' => $this->getComplianceStatus($score),
        ];
    }

    /**
     * ISO 31000 Risk Management Framework
     */
    public function getISO31000Compliance(): array
    {
        $changeStats = $this->changeRequestRepository->getStatistics();
        $risks = $this->riskRepository->findAll();

        $score = 100;

        // Change management integration
        if ($changeStats['total'] == 0) {
            $score -= 20;
        } else {
            if ($changeStats['pending_approval'] > 5) {
                $score -= 15;
            }
            if ($changeStats['overdue'] > 0) {
                $score -= min(20, $changeStats['overdue'] * 5);
            }
        }

        // Risk integration
        if (count($risks) == 0) {
            $score -= 30;
        }

        return [
            'score' => round($score),
            'change_requests_total' => $changeStats['total'],
            'pending_changes' => $changeStats['pending_approval'],
            'overdue_changes' => $changeStats['overdue'],
            'status' => $this->getComplianceStatus($score),
        ];
    }

    /**
     * Calculate overall compliance score
     */
    public function calculateOverallComplianceScore(): int
    {
        $iso27001 = $this->getISO27001Compliance()['score'];
        $iso22301 = $this->getISO22301Compliance()['score'];
        $iso27005 = $this->getISO27005Compliance()['score'];
        $iso31000 = $this->getISO31000Compliance()['score'];

        return round(($iso27001 + $iso22301 + $iso27005 + $iso31000) / 4);
    }

    /**
     * Get critical actions needed
     */
    public function getCriticalActions(): array
    {
        $actions = [];

        // Supplier critical actions
        $overdueAssessments = $this->supplierRepository->findOverdueAssessments();
        $criticalSuppliers = $this->supplierRepository->findCriticalSuppliers();

        foreach ($overdueAssessments as $supplier) {
            $actions[] = [
                'priority' => 'high',
                'category' => 'supplier',
                'action' => "Schedule security assessment for supplier: {$supplier->getName()}",
                'due_date' => $supplier->getNextAssessmentDate(),
            ];
        }

        foreach ($criticalSuppliers as $supplier) {
            if ($supplier->calculateRiskScore() > 70) {
                $actions[] = [
                    'priority' => 'critical',
                    'category' => 'supplier',
                    'action' => "High risk supplier requires immediate review: {$supplier->getName()}",
                    'risk_score' => $supplier->calculateRiskScore(),
                ];
            }
        }

        // BC critical actions
        $overdueTests = $this->bcPlanRepository->findOverdueTests();
        foreach ($overdueTests as $plan) {
            $actions[] = [
                'priority' => 'high',
                'category' => 'bc',
                'action' => "Schedule BC test for: {$plan->getName()}",
                'overdue_since' => $plan->getNextTestDate(),
            ];
        }

        // Interested party actions
        $overdueComms = $this->interestedPartyRepository->findOverdueCommunications();
        foreach ($overdueComms as $party) {
            $actions[] = [
                'priority' => 'medium',
                'category' => 'stakeholder',
                'action' => "Contact overdue for: {$party->getName()}",
                'due_date' => $party->getNextCommunication(),
            ];
        }

        // Risk acceptance actions
        $risks = $this->riskRepository->findAll();
        $pendingApproval = array_filter($risks, fn($r) => $r->isAcceptanceApprovalRequired());
        foreach ($pendingApproval as $risk) {
            $actions[] = [
                'priority' => 'high',
                'category' => 'risk',
                'action' => "Formal approval required for accepted risk: {$risk->getTitle()}",
                'risk_level' => $risk->getInherentRiskLevel(),
            ];
        }

        return $actions;
    }

    /**
     * Get improvement recommendations
     */
    public function getRecommendations(): array
    {
        $recommendations = [];

        $iso27001 = $this->getISO27001Compliance();
        if ($iso27001['score'] < 80) {
            $recommendations[] = [
                'area' => 'ISO 27001',
                'recommendation' => 'Improve supplier management and stakeholder communication',
                'impact' => 'high',
            ];
        }

        $iso22301 = $this->getISO22301Compliance();
        if ($iso22301['score'] < 80) {
            $recommendations[] = [
                'area' => 'ISO 22301',
                'recommendation' => 'Schedule BC plan tests and exercises',
                'impact' => 'critical',
            ];
        }

        $bcExerciseStats = $this->bcExerciseRepository->getStatistics();
        if ($bcExerciseStats['total'] < 2) {
            $recommendations[] = [
                'area' => 'BC Management',
                'recommendation' => 'Conduct at least 2 BC exercises per year',
                'impact' => 'high',
            ];
        }

        return $recommendations;
    }

    /**
     * Calculate BC readiness from plans
     */
    private function calculateBCReadiness(array $bcPlans): int
    {
        if (empty($bcPlans)) {
            return 0;
        }

        $totalReadiness = 0;
        foreach ($bcPlans as $plan) {
            $totalReadiness += $plan->getReadinessScore();
        }

        return round($totalReadiness / count($bcPlans));
    }

    /**
     * Get compliance status label
     */
    private function getComplianceStatus(float $score): string
    {
        return match(true) {
            $score >= 95 => 'excellent',
            $score >= 80 => 'good',
            $score >= 60 => 'acceptable',
            $score >= 40 => 'needs_improvement',
            default => 'critical'
        };
    }
}
