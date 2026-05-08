<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard\Dora;

use App\Entity\Tenant;
use App\Repository\BCExerciseRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckInterface;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardCheckResult;
use DateTimeImmutable;

/**
 * W4-D / DORA Art. 24-27 — confirms tenants flagged as significant
 * scheduled or executed a Threat-Led Penetration Test (TLPT) within
 * the last 36 months.
 *
 * Per Art. 26.1 + RTS JC 2024 29 (TIBER-EU aligned), significant
 * financial entities MUST run a TLPT at least every three years on
 * their critical or important functions. The wizard signals
 * "significant" via the `dora.is_significant` tenant-policy-setting
 * (W1-A) — non-significant tenants pass automatically (the obligation
 * does not apply).
 *
 * Detection heuristic: any {@see \App\Entity\BCExercise} row whose
 * `name`, `description`, `scope`, or `objectives` mentions "tlpt" /
 * "threat-led" / "tiber" — these are the canonical project tags. For
 * tenants in the significant cohort the most recent qualifying
 * exercise must have an `exerciseDate` within the trailing 36 months
 * window OR be in `planned`/`in_progress` status.
 */
final class DoraTlptCadenceCheck implements PolicyWizardCheckInterface
{
    public const CHECK_ID = 'dora_tlpt_cadence';
    private const STANDARD = 'dora';

    /** Cadence ceiling per DORA Art. 26.1 — 3 years = 36 months. */
    public const CADENCE_MONTHS = 36;

    /** Tenant-policy-setting key flagging the entity as DORA-significant. */
    public const SETTING_KEY_SIGNIFICANT = 'dora.is_significant';

    public function __construct(
        private readonly BCExerciseRepository $exerciseRepository,
        private readonly \App\Repository\TenantPolicySettingRepository $tenantPolicySettingRepository,
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

        if (!$this->isTenantDoraSignificant($tenant)) {
            // TLPT obligation does not apply — pass with neutral note.
            return new PolicyWizardCheckResult(
                checkId: self::CHECK_ID,
                score: 100.0,
                passed: true,
                details: [
                    'significant' => false,
                    'reason' => 'not_in_tlpt_scope',
                ],
            );
        }

        $cutoff = (new DateTimeImmutable())->modify(sprintf('-%d months', self::CADENCE_MONTHS));

        $exercises = $this->exerciseRepository->createQueryBuilder('e')
            ->where('e.tenant = :tenant')
            ->andWhere('LOWER(e.name) LIKE :tlpt OR LOWER(e.description) LIKE :tlpt OR LOWER(e.scope) LIKE :tlpt OR LOWER(e.objectives) LIKE :tlpt
                       OR LOWER(e.name) LIKE :threatled OR LOWER(e.description) LIKE :threatled OR LOWER(e.scope) LIKE :threatled OR LOWER(e.objectives) LIKE :threatled
                       OR LOWER(e.name) LIKE :tiber OR LOWER(e.description) LIKE :tiber OR LOWER(e.scope) LIKE :tiber OR LOWER(e.objectives) LIKE :tiber')
            ->setParameter('tenant', $tenant)
            ->setParameter('tlpt', '%tlpt%')
            ->setParameter('threatled', '%threat-led%')
            ->setParameter('tiber', '%tiber%')
            ->orderBy('e.exerciseDate', 'DESC')
            ->getQuery()
            ->getResult();

        foreach ($exercises as $exercise) {
            $status = $exercise->getStatus();
            $date = $exercise->getExerciseDate();
            // Future or in-progress exercises count as "scheduled" per Art. 26.1.
            if (in_array($status, ['planned', 'in_progress'], true)) {
                return new PolicyWizardCheckResult(
                    checkId: self::CHECK_ID,
                    score: 100.0,
                    passed: true,
                    details: [
                        'significant' => true,
                        'last_or_planned_exercise_id' => $exercise->getId(),
                        'status' => $status,
                    ],
                );
            }
            // Completed exercise must be within the trailing window.
            if ($date !== null && $date >= $cutoff) {
                return new PolicyWizardCheckResult(
                    checkId: self::CHECK_ID,
                    score: 100.0,
                    passed: true,
                    details: [
                        'significant' => true,
                        'last_or_planned_exercise_id' => $exercise->getId(),
                        'status' => $status,
                        'exercise_date' => $date->format('Y-m-d'),
                    ],
                );
            }
        }

        return new PolicyWizardCheckResult(
            checkId: self::CHECK_ID,
            score: 0.0,
            passed: false,
            details: [
                'significant' => true,
                'matching_exercises' => count($exercises),
                'cadence_months' => self::CADENCE_MONTHS,
            ],
            gap: [
                'title' => sprintf('compliance_check.%s.fail_message', self::CHECK_ID),
                'priority' => 'critical',
                'route' => 'app_bc_exercise_index',
                'translation_domain' => 'policy_wizard',
            ],
        );
    }

    private function isTenantDoraSignificant(Tenant $tenant): bool
    {
        $setting = $this->tenantPolicySettingRepository->findOneByTenantAndKey(
            $tenant,
            self::SETTING_KEY_SIGNIFICANT,
        );
        if ($setting === null) {
            return false;
        }
        $value = $setting->getValue();
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }
}
