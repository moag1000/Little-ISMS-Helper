<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard;

use App\Entity\Tenant;

/**
 * GDPR coverage checks (DSR, DPIA) of {@see CoverageCheckService}; uses its
 * `dataSubjectRequestRepository`, `processingActivityRepository` and `riskRepository`.
 */
trait PrivacyCoverageChecksTrait
{
    /**
     * Counts open vs. completed Data-Subject Requests (GDPR Art. 12-22).
     * Score = % of requests in terminal status (completed or rejected),
     * rounded to 1 decimal. Zero requests are no evidence that the process
     * works (untested), so the check scores 0 with reason no_data.
     */
    public function checkDsrCoverage(array $check, ?Tenant $tenant): array
    {
        $qbTotal = $this->dataSubjectRequestRepository->createQueryBuilder('d')->select('COUNT(d.id)');
        $qbDone = $this->dataSubjectRequestRepository->createQueryBuilder('d')->select('COUNT(d.id)')
            ->where('d.status IN (:done)')->setParameter('done', ['completed', 'rejected']);
        if ($tenant !== null) {
            $qbTotal->andWhere('d.tenant = :t')->setParameter('t', $tenant);
            $qbDone->andWhere('d.tenant = :t')->setParameter('t', $tenant);
        }

        $total = (int) $qbTotal->getQuery()->getSingleScalarResult();
        $done = (int) $qbDone->getQuery()->getSingleScalarResult();

        if ($total === 0) {
            return NoDataResult::build(
                'wizard.gap.no_dsr',
                'app_data_subject_request_index',
                $check + ['priority' => 'medium'],
                ['total' => 0, 'completed' => 0],
            );
        }

        $score = round(($done / $total) * 100, 1);

        $gap = null;
        if ($score < 90) {
            $gap = [
                'title' => 'wizard.gap.dsr_open_high',
                'description' => 'wizard.gap.dsr_open_high_desc',
                'priority' => 'high',
                'route' => $check['route'] ?? 'app_data_subject_request_index',
            ];
        }

        return [
            'score' => $score,
            'details' => ['total' => $total, 'completed' => $done],
            'gap' => $gap,
        ];
    }

    /**
     * Of all ProcessingActivity rows flagged isHighRisk=true (= DPIA required
     * per Art. 35 DSGVO), how many have at least one linked Risk row whose
     * requiresDPIA flag is also true? Lower bound: 0 (no DPIA performed).
     * Upper bound: 100 (every high-risk activity has a documented DPIA risk).
     * No processing activities at all scores 0 (no_data: the record of processing
     * is missing). Activities recorded but none high-risk means no DPIA is
     * required: score 100 with reason not_applicable.
     */
    public function checkDpiaCoverage(array $check, ?Tenant $tenant): array
    {
        $qbHighRisk = $this->processingActivityRepository->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.isHighRisk = :hr')->setParameter('hr', true);
        if ($tenant !== null) {
            $qbHighRisk->andWhere('p.tenant = :t')->setParameter('t', $tenant);
        }
        $highRisk = (int) $qbHighRisk->getQuery()->getSingleScalarResult();

        if ($highRisk === 0) {
            $qbAll = $this->processingActivityRepository->createQueryBuilder('p')->select('COUNT(p.id)');
            if ($tenant !== null) {
                $qbAll->andWhere('p.tenant = :t')->setParameter('t', $tenant);
            }
            if ((int) $qbAll->getQuery()->getSingleScalarResult() === 0) {
                return NoDataResult::build(
                    'wizard.gap.no_processing_activities',
                    'app_processing_activity_index',
                    $check,
                    ['high_risk_activities' => 0, 'documented_dpias' => 0],
                );
            }

            return [
                'score' => 100,
                'details' => ['reason' => 'not_applicable', 'high_risk_activities' => 0, 'documented_dpias' => 0],
                'gap' => null,
            ];
        }

        $qbDpia = $this->riskRepository->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.requiresDPIA = :rq')->setParameter('rq', true);
        if ($tenant !== null) {
            $qbDpia->andWhere('r.tenant = :t')->setParameter('t', $tenant);
        }
        $documented = (int) $qbDpia->getQuery()->getSingleScalarResult();

        // Cap at 100 — a tenant might over-document (more DPIA-risks than
        // high-risk activities); we don't want a score >100.
        $score = round(min(100, ($documented / $highRisk) * 100), 1);

        $gap = null;
        if ($score < 100) {
            $gap = [
                'title' => 'wizard.gap.dpia_missing',
                'description' => 'wizard.gap.dpia_missing_desc',
                'priority' => 'critical',
                'route' => $check['route'] ?? 'app_processing_activity_index',
            ];
        }

        return [
            'score' => $score,
            'details' => ['high_risk_activities' => $highRisk, 'documented_dpias' => $documented],
            'gap' => $gap,
        ];
    }
}
