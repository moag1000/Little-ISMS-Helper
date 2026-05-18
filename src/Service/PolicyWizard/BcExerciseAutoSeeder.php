<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\BCExercise;
use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Enum\BCExerciseStatus;
use App\Service\AuditLogger;
use DateInterval;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Policy-Wizard W5-B — auto-seed BCExercise placeholder records.
 *
 * Per BCM specialist input
 * (`docs/plans/policy-wizard/04-bcm-input.md` §2.9 + §7.2 + §9.4):
 * when the wizard generates the `exercise_testing_programme`
 * governance document for a BCM-scoped run, four BCExercise placeholder
 * records are auto-created so external auditors immediately see
 * scheduled exercise activity (Cl. 8.6 `shall exercise and test`).
 *
 * Each placeholder is one calendar quarter apart starting `now + 30
 * days`, with exercise types alternating across the four primary types
 * recognised by ISO 22301 / BSI 200-4 Kap. 8:
 *  - tabletop      (Plan-Durchsprache)
 *  - walkthrough   (functional walkthrough)
 *  - simulation    (functional simulation)
 *  - full_test     (Vollübung — note: BCExercise enum uses `full_test`,
 *                  spec calls this "test", we map to BCExercise's
 *                  canonical type name)
 *
 * Idempotency: the seeder logs a single audit-trail event per run.
 * `WizardRun.id` is used as the natural correlation key — re-running
 * `complete()` on the same run is gated by the orchestrator (status
 * check) and additionally guarded here via the audit-log key so the
 * service is safe to call defensively. We DO NOT query BCExercise rows
 * for de-duplication: each completed wizard run is treated as a fresh
 * 12-month programme (the existing placeholder rows can be hand-edited
 * after the fact, no auto-update).
 */
final class BcExerciseAutoSeeder
{
    public const string AUDIT_TAG = 'bcm-exercise-auto-seed';

    /**
     * Quarterly cadence default. Other frequencies are supported via
     * tenant input but the seeder always emits exactly four records
     * to satisfy the §7.2 mitigation ("at least 4 placeholders per
     * 12 months").
     */
    public const int PLACEHOLDER_COUNT = 4;

    /**
     * Type rotation. Order is intentional: tabletop is first because
     * it has the lowest activation cost and nudges the BCM team into
     * exercise rhythm. The fourth slot (`full_test`) maps to the
     * spec's "test" placeholder.
     *
     * @var list<string>
     */
    public const array TYPE_ROTATION = [
        'tabletop',
        'walkthrough',
        'simulation',
        'full_test',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Create four planned BCExercise records for the next 12 months.
     *
     * Reads the optional frequency from the wizard run's stored input
     * `operational_baselines.bcm.exercise_frequency`. Allowed values:
     * `quarterly` (default), `semi_annual`, `annual`. The values drive
     * the gap between placeholders (3, 6, 12 months) but the seeder
     * always emits 4 records to satisfy the auditor mitigation in
     * §7.2 ("show 12 months of scheduled activity"). When
     * `semi_annual`/`annual` is selected the placeholders simply span
     * a longer horizon.
     */
    public function seedExerciseProgramme(Tenant $tenant, WizardRun $run): void
    {
        $monthsBetween = $this->resolveCadenceMonths($run);
        $startDate = (new DateTimeImmutable('today'))->add(new DateInterval('P30D'));

        $createdIds = [];

        for ($i = 0; $i < self::PLACEHOLDER_COUNT; $i++) {
            $type = self::TYPE_ROTATION[$i % count(self::TYPE_ROTATION)];
            $offsetMonths = $monthsBetween * $i;

            $plannedDate = $this->firstOfNextQuarterFrom(
                $startDate,
                $offsetMonths,
            );

            $exercise = $this->buildPlaceholder($tenant, $run, $type, $plannedDate, $i + 1);

            try {
                $this->entityManager->persist($exercise);
            } catch (Throwable $error) {
                $this->logger->warning(
                    'PolicyWizard BcExerciseAutoSeeder: persist failed; aborting seed',
                    [
                        'wizard_run_id' => $run->getId(),
                        'tenant_id' => $tenant->getId(),
                        'iteration' => $i,
                        'error' => $error->getMessage(),
                    ],
                );
                throw $error;
            }
            $createdIds[] = $exercise;
        }

        $this->entityManager->flush();

        // Audit-log: capture the seed event with the four placeholder
        // BCExercise IDs so external auditors can trace the
        // wizard-run → BCExercise records linkage.
        $this->auditLogger->logCustom(
            action: 'bcm_exercise_auto_seed',
            entityType: 'WizardRun',
            entityId: $run->getId(),
            oldValues: null,
            newValues: [
                'tag' => self::AUDIT_TAG,
                'tenant_id' => $tenant->getId(),
                'wizard_run_id' => $run->getId(),
                'placeholder_count' => count($createdIds),
                'cadence_months' => $monthsBetween,
                'bc_exercise_ids' => array_map(
                    static fn (BCExercise $e): ?int => $e->getId(),
                    $createdIds,
                ),
                'types' => array_map(
                    static fn (BCExercise $e): ?string => $e->getExerciseType(),
                    $createdIds,
                ),
            ],
            description: sprintf(
                '[%s] Auto-seeded %d BCExercise placeholders for tenant #%d (wizard run #%d)',
                self::AUDIT_TAG,
                count($createdIds),
                $tenant->getId() ?? 0,
                $run->getId() ?? 0,
            ),
        );
    }

    private function buildPlaceholder(
        Tenant $tenant,
        WizardRun $run,
        string $exerciseType,
        DateTimeImmutable $plannedDate,
        int $quarterIndex,
    ): BCExercise {
        $exercise = new BCExercise();
        $exercise->setTenant($tenant);
        $exercise->setName(sprintf(
            'BCM Exercise Q%d (%s) — Auto-seeded',
            $quarterIndex,
            $exerciseType,
        ));
        $exercise->setExerciseType($exerciseType);
        $exercise->setDescription(sprintf(
            'Placeholder exercise auto-created by Policy-Wizard run #%d. '
            . 'BCM team should refine scope, objectives, scenario and '
            . 'participants before execution.',
            $run->getId() ?? 0,
        ));
        $exercise->setScope('TBD — refine before execution.');
        $exercise->setObjectives(
            'Validate BC plan activation, communication cascade and recovery sequencing per ISO 22301 Cl. 8.6.',
        );
        $exercise->setExerciseDate(DateTime::createFromImmutable($plannedDate));
        $exercise->setParticipants('TBD — Crisis Team + BCM Officer + Process Owners.');
        $exercise->setFacilitator('BCM Officer');
        $exercise->setStatus(BCExerciseStatus::Planned); // @phpstan-ignore lifecycle.directSetStatus (initial state on pre-persist entity; 'planned' is the bc_exercise_lifecycle initial_marking)
        $exercise->setReportCompleted(false);

        return $exercise;
    }

    /**
     * Map `operational_baselines.bcm.exercise_frequency` to the gap
     * (in months) between placeholder dates. Defaults to quarterly.
     */
    private function resolveCadenceMonths(WizardRun $run): int
    {
        $inputs = $run->getInputs() ?? [];
        $opsBaselines = $inputs['operational_baselines'] ?? [];
        if (!is_array($opsBaselines)) {
            return 3;
        }
        $bcmBlock = $opsBaselines['bcm'] ?? [];
        if (!is_array($bcmBlock)) {
            return 3;
        }
        $frequency = $bcmBlock['exercise_frequency'] ?? 'quarterly';

        return match ($frequency) {
            'semi_annual' => 6,
            'annual' => 12,
            default => 3,
        };
    }

    /**
     * Snap the date to the first of the *next* month at-or-after the
     * slot (`startDate` + `offsetMonths`). Mirrors the spec "first day
     * of each quarter from now+30d" — we never reach back into the
     * past (so the earliest planned date is always > start+30d).
     */
    private function firstOfNextQuarterFrom(DateTimeImmutable $start, int $offsetMonths): DateTimeImmutable
    {
        $stepped = $offsetMonths > 0
            ? $start->add(new DateInterval('P' . $offsetMonths . 'M'))
            : $start;

        // Snap to first of NEXT month so we never round down past the
        // start anchor.
        $firstOfMonth = $stepped->setDate(
            (int) $stepped->format('Y'),
            (int) $stepped->format('m'),
            1,
        )->setTime(0, 0, 0);

        return $firstOfMonth->add(new DateInterval('P1M'));
    }
}
