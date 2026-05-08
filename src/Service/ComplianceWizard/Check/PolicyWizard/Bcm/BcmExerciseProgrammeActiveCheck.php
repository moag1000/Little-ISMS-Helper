<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Bcm;

use App\Entity\Tenant;
use App\Repository\BCExerciseRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;
use DateTimeImmutable;

/**
 * W5-C / ISO 22301 Cl. 8.6 — confirms the tenant has at least one
 * BCExercise within the last 12 months OR scheduled for the next 12.
 *
 * Per `docs/plans/policy-wizard/04-bcm-input.md` §7.2 BCMS without
 * exercise evidence is an external-auditor major nonconformity. The
 * Exercise & Testing Programme (§2.9) MUST be backed by actual
 * BCExercise rows so the audit trail isn't a hollow promise.
 *
 * Detection heuristic:
 * - Any `BCExercise` with `exerciseDate` in the trailing 12 months
 *   AND status `completed` (executed exercise) → pass
 * - Any `BCExercise` with `exerciseDate` in the next 12 months AND
 *   status `planned` or `in_progress` → pass (programme is active)
 * - Otherwise: fail (Cl. 8.6 nonconformity).
 */
final class BcmExerciseProgrammeActiveCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'bcm_exercise_programme_active';
    private const STANDARD = 'bcm';
    public const WINDOW_MONTHS_BACKWARD = 12;
    public const WINDOW_MONTHS_FORWARD = 12;

    public function __construct(
        private readonly BCExerciseRepository $exerciseRepository,
    ) {
    }

    public function getCheckId(): string
    {
        return self::CHECK_ID;
    }

    public function getStandard(): string
    {
        return self::STANDARD;
    }

    public function run(?Tenant $tenant): PolicyWizardCheckResult
    {
        if ($tenant === null) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 0.0,
                passed: false,
                details: ['reason' => 'no_tenant'],
            );
        }

        $now = new DateTimeImmutable();
        $backCutoff = $now->modify(sprintf('-%d months', self::WINDOW_MONTHS_BACKWARD));
        $forwardCutoff = $now->modify(sprintf('+%d months', self::WINDOW_MONTHS_FORWARD));

        $exercises = $this->exerciseRepository->createQueryBuilder('e')
            ->where('e.tenant = :tenant')
            ->andWhere('e.exerciseDate >= :back_cutoff')
            ->andWhere('e.exerciseDate <= :forward_cutoff')
            ->setParameter('tenant', $tenant)
            ->setParameter('back_cutoff', $backCutoff)
            ->setParameter('forward_cutoff', $forwardCutoff)
            ->orderBy('e.exerciseDate', 'DESC')
            ->getQuery()
            ->getResult();

        $completedRecent = 0;
        $planned = 0;
        foreach ($exercises as $exercise) {
            $status = $exercise->getStatus();
            $date = $exercise->getExerciseDate();
            if ($date === null) {
                continue;
            }
            if ($date <= $now && $status === 'completed') {
                $completedRecent++;
            }
            if ($date >= $now && in_array($status, ['planned', 'in_progress'], true)) {
                $planned++;
            }
        }

        if ($completedRecent > 0 || $planned > 0) {
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'completed_in_last_12m' => $completedRecent,
                    'planned_next_12m' => $planned,
                ],
            );
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: 0.0,
            passed: false,
            details: [
                'completed_in_last_12m' => 0,
                'planned_next_12m' => 0,
                'window_months_back' => self::WINDOW_MONTHS_BACKWARD,
                'window_months_forward' => self::WINDOW_MONTHS_FORWARD,
            ],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'critical',
                'route' => 'app_bc_exercise_index',
                'translation_domain' => 'policy_wizard',
            ],
        );
    }
}
