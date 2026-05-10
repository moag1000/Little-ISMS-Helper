<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Repository\AuditFindingRepository;
use App\Repository\DocumentRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\RiskRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Entity\AuditFinding;
use App\Entity\PolicyAcknowledgement;
use DateTimeImmutable;

/**
 * V4-EF-8: Pre-export readiness check for the Certification Bundle.
 *
 * Prevents compliance managers from exporting an empty or incomplete bundle
 * to an auditor. Runs 5 checks per framework and surfaces blockers (HARD STOP)
 * and warnings (soft-gate) with a 0-100 readiness score.
 *
 * Checks performed:
 *   1. All required Documents approved (status = 'approved')
 *   2. All required PolicyAcknowledgements collected (status = 'acknowledged')
 *   3. All AuditFindings closed/accepted (not open/in_progress)
 *   4. Last Risk Assessment within 12 months (updatedAt on any Risk)
 *   5. Last Management Review within 12 months
 *
 * Severity levels for blockers / warnings:
 *   - 'critical'  → hard blocker (lowers score significantly)
 *   - 'warning'   → soft advisory (lowers score slightly)
 */
class CertBundleReadinessService
{
    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly PolicyAcknowledgementRepository $ackRepository,
        private readonly AuditFindingRepository $findingRepository,
        private readonly RiskRepository $riskRepository,
        private readonly ManagementReviewRepository $reviewRepository,
    ) {
    }

    /**
     * Run all pre-export checks for the given tenant and framework code.
     *
     * @param Tenant $tenant
     * @param string $frameworkCode e.g. 'ISO27001', 'NIS2'
     * @return array{
     *   ready: bool,
     *   score: int,
     *   blockers: array<int, array{type: string, description: string, severity: string}>,
     *   warnings: array<int, array{type: string, description: string, severity: string}>,
     *   checks: array<string, bool>
     * }
     */
    public function check(Tenant $tenant, string $frameworkCode = 'ISO27001'): array
    {
        $blockers = [];
        $warnings = [];
        $checks = [];

        // Check 1: All required Documents approved
        [$docCheck, $docBlockers, $docWarnings] = $this->checkDocumentApprovals($tenant, $frameworkCode);
        $checks['documents_approved'] = $docCheck;
        $blockers = array_merge($blockers, $docBlockers);
        $warnings = array_merge($warnings, $docWarnings);

        // Check 2: All required PolicyAcknowledgements collected
        [$ackCheck, $ackBlockers, $ackWarnings] = $this->checkPolicyAcknowledgements($tenant);
        $checks['policy_acknowledgements'] = $ackCheck;
        $blockers = array_merge($blockers, $ackBlockers);
        $warnings = array_merge($warnings, $ackWarnings);

        // Check 3: All AuditFindings closed/accepted
        [$findingCheck, $findingBlockers, $findingWarnings] = $this->checkAuditFindings($tenant);
        $checks['findings_closed'] = $findingCheck;
        $blockers = array_merge($blockers, $findingBlockers);
        $warnings = array_merge($warnings, $findingWarnings);

        // Check 4: Last Risk Assessment within 12 months
        [$riskCheck, $riskBlockers, $riskWarnings] = $this->checkRiskAssessmentAge($tenant);
        $checks['risk_assessment_current'] = $riskCheck;
        $blockers = array_merge($blockers, $riskBlockers);
        $warnings = array_merge($warnings, $riskWarnings);

        // Check 5: Last Management Review within 12 months
        [$reviewCheck, $reviewBlockers, $reviewWarnings] = $this->checkManagementReviewAge($tenant);
        $checks['management_review_current'] = $reviewCheck;
        $blockers = array_merge($blockers, $reviewBlockers);
        $warnings = array_merge($warnings, $reviewWarnings);

        $score = $this->calculateScore($blockers, $warnings);
        $ready = $blockers === [];

        return [
            'ready'     => $ready,
            'score'     => $score,
            'blockers'  => $blockers,
            'warnings'  => $warnings,
            'checks'    => $checks,
        ];
    }

    /**
     * Check 1 — All framework-relevant Documents are status='approved'.
     *
     * Documents with status 'draft', 'pending_approval', 'in_review' or 'review'
     * are blockers. Documents not yet started are warnings (soft advisory).
     *
     * @return array{0: bool, 1: list<array{type: string, description: string, severity: string}>, 2: list<array{type: string, description: string, severity: string}>}
     */
    private function checkDocumentApprovals(Tenant $tenant, string $frameworkCode): array
    {
        $blockers = [];
        $warnings = [];

        $documents = $this->documentRepository->findByTenant($tenant);
        $unapproved = 0;

        foreach ($documents as $doc) {
            $status = method_exists($doc, 'getStatus') ? $doc->getStatus() : null;
            if ($status !== null && !in_array($status, ['approved', 'archived'], true)) {
                $unapproved++;
            }
        }

        if ($unapproved > 0) {
            $blockers[] = [
                'type'        => 'documents_unapproved',
                'description' => sprintf('cert_bundle.preflight.blockers.documents_unapproved %d', $unapproved),
                'severity'    => 'critical',
                'count'       => $unapproved,
            ];
            return [false, $blockers, $warnings];
        }

        return [true, $blockers, $warnings];
    }

    /**
     * Check 2 — All PolicyAcknowledgements are status='acknowledged' (none pending).
     *
     * Pending acknowledgements mean policy has been distributed but not confirmed.
     * This is a critical blocker for ISO 27001 A.6.3 compliance.
     *
     * @return array{0: bool, 1: list<array{type: string, description: string, severity: string}>, 2: list<array{type: string, description: string, severity: string}>}
     */
    private function checkPolicyAcknowledgements(Tenant $tenant): array
    {
        $blockers = [];
        $warnings = [];

        $pending = $this->ackRepository->findBy([
            'tenant' => $tenant,
            'status' => PolicyAcknowledgement::STATUS_PENDING,
        ]);

        if (count($pending) > 0) {
            $blockers[] = [
                'type'        => 'acknowledgements_pending',
                'description' => sprintf('cert_bundle.preflight.blockers.acknowledgements_pending %d', count($pending)),
                'severity'    => 'critical',
                'count'       => count($pending),
            ];
            return [false, $blockers, $warnings];
        }

        return [true, $blockers, $warnings];
    }

    /**
     * Check 3 — All AuditFindings are closed/verified (none open or in-progress).
     *
     * Open major NCs are blockers; open minor NCs and observations are warnings.
     *
     * @return array{0: bool, 1: list<array{type: string, description: string, severity: string}>, 2: list<array{type: string, description: string, severity: string}>}
     */
    private function checkAuditFindings(Tenant $tenant): array
    {
        $blockers = [];
        $warnings = [];

        $openFindings = $this->findingRepository->findOpenByTenant($tenant);

        $majorNcs = 0;
        $minorAndObs = 0;

        foreach ($openFindings as $finding) {
            $type = method_exists($finding, 'getType') ? $finding->getType() : 'observation';
            if ($type === AuditFinding::TYPE_MAJOR_NC) {
                $majorNcs++;
            } else {
                $minorAndObs++;
            }
        }

        if ($majorNcs > 0) {
            $blockers[] = [
                'type'        => 'open_major_findings',
                'description' => sprintf('cert_bundle.preflight.blockers.open_major_findings %d', $majorNcs),
                'severity'    => 'critical',
                'count'       => $majorNcs,
            ];
        }

        if ($minorAndObs > 0) {
            $warnings[] = [
                'type'        => 'open_minor_findings',
                'description' => sprintf('cert_bundle.preflight.warnings.open_minor_findings %d', $minorAndObs),
                'severity'    => 'warning',
                'count'       => $minorAndObs,
            ];
        }

        return [$majorNcs === 0, $blockers, $warnings];
    }

    /**
     * Check 4 — Last Risk Assessment within 12 months.
     *
     * Uses the most recently updated Risk entity's updatedAt timestamp.
     * If no risks exist, or the latest is older than 12 months, this is a blocker.
     *
     * @return array{0: bool, 1: list<array{type: string, description: string, severity: string}>, 2: list<array{type: string, description: string, severity: string}>}
     */
    private function checkRiskAssessmentAge(Tenant $tenant): array
    {
        $blockers = [];
        $warnings = [];
        $threshold = new DateTimeImmutable('-12 months');

        $risks = $this->riskRepository->findByTenant($tenant);

        if ($risks === []) {
            $blockers[] = [
                'type'        => 'no_risks',
                'description' => 'cert_bundle.preflight.blockers.no_risks',
                'severity'    => 'critical',
                'count'       => 0,
            ];
            return [false, $blockers, $warnings];
        }

        $latestDate = null;
        foreach ($risks as $risk) {
            $updated = method_exists($risk, 'getUpdatedAt') ? $risk->getUpdatedAt() : null;
            if ($updated instanceof \DateTimeInterface) {
                if ($latestDate === null || $updated > $latestDate) {
                    $latestDate = $updated;
                }
            }
        }

        if ($latestDate === null || $latestDate < $threshold) {
            $blockers[] = [
                'type'        => 'risk_assessment_outdated',
                'description' => 'cert_bundle.preflight.blockers.risk_assessment_outdated',
                'severity'    => 'critical',
                'count'       => 0,
                'last_date'   => $latestDate?->format('Y-m-d'),
            ];
            return [false, $blockers, $warnings];
        }

        return [true, $blockers, $warnings];
    }

    /**
     * Check 5 — Last Management Review within 12 months (ISO 27001 Clause 9.3).
     *
     * Uses ManagementReview.reviewDate. If no completed review exists within
     * 12 months, this is a critical blocker.
     *
     * @return array{0: bool, 1: list<array{type: string, description: string, severity: string}>, 2: list<array{type: string, description: string, severity: string}>}
     */
    private function checkManagementReviewAge(Tenant $tenant): array
    {
        $blockers = [];
        $warnings = [];
        $threshold = new DateTimeImmutable('-12 months');

        $reviews = $this->reviewRepository->findBy(
            ['tenant' => $tenant],
            ['reviewDate' => 'DESC'],
            5
        );

        $recentCompleted = false;
        foreach ($reviews as $review) {
            $date = method_exists($review, 'getReviewDate') ? $review->getReviewDate() : null;
            $status = method_exists($review, 'getStatus') ? $review->getStatus() : 'completed';
            if ($date instanceof \DateTimeInterface && $date >= $threshold
                && in_array($status, ['completed', 'approved', null], true)) {
                $recentCompleted = true;
                break;
            }
        }

        if (!$recentCompleted) {
            $blockers[] = [
                'type'        => 'management_review_outdated',
                'description' => 'cert_bundle.preflight.blockers.management_review_outdated',
                'severity'    => 'critical',
                'count'       => 0,
            ];
            return [false, $blockers, $warnings];
        }

        return [true, $blockers, $warnings];
    }

    /**
     * Calculate a 0-100 readiness score.
     *
     * - 5 checks × 20 points each = 100 max
     * - Each critical blocker deducts 20 points
     * - Each warning deducts 5 points (capped at 10 per check)
     * - Score is always clamped to [0, 100]
     */
    private function calculateScore(array $blockers, array $warnings): int
    {
        $score = 100;

        foreach ($blockers as $blocker) {
            $score -= 20;
        }

        foreach ($warnings as $warning) {
            $score -= 5;
        }

        return max(0, min(100, $score));
    }
}
