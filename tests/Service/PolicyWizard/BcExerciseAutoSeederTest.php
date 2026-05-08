<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\BCExercise;
use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\BcExerciseAutoSeeder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W5-B — BcExerciseAutoSeeder unit tests.
 *
 * Exercises the BCExercise placeholder seed triggered when a BCM-scoped
 * wizard run completes (`exercise_testing_programme` topic emitted).
 * Per `docs/plans/policy-wizard/04-bcm-input.md` §2.9 + §7.2.
 */
#[AllowMockObjectsWithoutExpectations]
final class BcExerciseAutoSeederTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    /** @var list<array{action: string, entityType: string, entityId: ?int, newValues: ?array<string, mixed>, description: ?string}> */
    private array $auditEntries = [];

    protected function setUp(): void
    {
        $this->persisted = [];
        $this->auditEntries = [];
    }

    private function makeTenant(int $id = 7): Tenant
    {
        $t = $this->createStub(Tenant::class);
        $t->method('getId')->willReturn($id);
        return $t;
    }

    private function makeRun(int $id = 42, ?array $inputs = null): WizardRun
    {
        $run = new WizardRun();
        $run->setMode('full');
        if ($inputs !== null) {
            $run->setInputs($inputs);
        }
        $reflection = new \ReflectionProperty(WizardRun::class, 'id');
        $reflection->setValue($run, $id);
        return $run;
    }

    private function makeSeeder(): BcExerciseAutoSeeder
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
            // Stamp synthetic id so audit-log call sees a value.
            if ($entity instanceof BCExercise && $entity->getId() === null) {
                $reflection = new \ReflectionProperty(BCExercise::class, 'id');
                $reflection->setValue($entity, count($this->persisted) + 100);
            }
        });
        $em->method('flush');

        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->method('logCustom')->willReturnCallback(
            function (
                string $action,
                string $entityType,
                ?int $entityId = null,
                ?array $oldValues = null,
                ?array $newValues = null,
                ?string $description = null,
                ?string $userName = null,
            ): void {
                $this->auditEntries[] = [
                    'action' => $action,
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                    'newValues' => $newValues,
                    'description' => $description,
                ];
            },
        );

        return new BcExerciseAutoSeeder($em, $auditLogger);
    }

    /**
     * @return list<BCExercise>
     */
    private function persistedExercises(): array
    {
        return array_values(array_filter(
            $this->persisted,
            static fn (object $o): bool => $o instanceof BCExercise,
        ));
    }

    #[Test]
    public function seedsFourPlaceholderExercises(): void
    {
        $seeder = $this->makeSeeder();
        $tenant = $this->makeTenant();
        $run = $this->makeRun();

        $seeder->seedExerciseProgramme($tenant, $run);

        $exercises = $this->persistedExercises();
        self::assertCount(4, $exercises, 'four BCExercise placeholders are created');

        foreach ($exercises as $ex) {
            self::assertSame('planned', $ex->getStatus());
            self::assertSame($tenant, $ex->getTenant());
            self::assertNotEmpty($ex->getName());
            self::assertNotEmpty($ex->getScope());
            self::assertNotEmpty($ex->getObjectives());
            self::assertNotEmpty($ex->getParticipants());
            self::assertNotEmpty($ex->getFacilitator());
            self::assertNotNull($ex->getExerciseDate());
        }
    }

    #[Test]
    public function exerciseTypesAlternateInRotation(): void
    {
        $seeder = $this->makeSeeder();

        $seeder->seedExerciseProgramme($this->makeTenant(), $this->makeRun());

        $types = array_map(
            static fn (BCExercise $e): ?string => $e->getExerciseType(),
            $this->persistedExercises(),
        );

        self::assertSame(
            ['tabletop', 'walkthrough', 'simulation', 'full_test'],
            $types,
            'exercise types alternate per the canonical rotation',
        );
    }

    #[Test]
    public function plannedDatesSpan12MonthsFromNowPlus30Days(): void
    {
        $seeder = $this->makeSeeder();

        $before = new \DateTimeImmutable('today');
        $seeder->seedExerciseProgramme($this->makeTenant(), $this->makeRun());

        $exercises = $this->persistedExercises();
        self::assertCount(4, $exercises);

        $earliest = $before->modify('+29 days');
        $latest = $before->modify('+18 months');

        $previousTimestamp = null;
        foreach ($exercises as $idx => $ex) {
            $date = $ex->getExerciseDate();
            self::assertNotNull($date);
            $ts = $date->getTimestamp();

            self::assertGreaterThanOrEqual(
                $earliest->getTimestamp(),
                $ts,
                sprintf('exercise %d is at least ~30 days in the future', $idx),
            );
            self::assertLessThan(
                $latest->getTimestamp(),
                $ts,
                sprintf('exercise %d is within ~18 months', $idx),
            );

            // Each subsequent exercise is later than the previous (default
            // quarterly cadence = +3 months).
            if ($previousTimestamp !== null) {
                self::assertGreaterThan(
                    $previousTimestamp,
                    $ts,
                    sprintf('exercise %d is later than %d', $idx, $idx - 1),
                );
            }
            $previousTimestamp = $ts;

            // First-of-month snap.
            self::assertSame(
                '01',
                $date->format('d'),
                sprintf('exercise %d planned date snapped to first of month', $idx),
            );
        }
    }

    #[Test]
    public function annualCadenceSpansLongerHorizon(): void
    {
        $seeder = $this->makeSeeder();

        $seeder->seedExerciseProgramme(
            $this->makeTenant(),
            $this->makeRun(99, [
                'operational_baselines' => [
                    'bcm' => [
                        'exercise_frequency' => 'annual',
                    ],
                ],
            ]),
        );

        $exercises = $this->persistedExercises();
        self::assertCount(
            4,
            $exercises,
            'still emits 4 placeholders even when the cadence is annual',
        );

        // Last exercise should be roughly 3 years out (4 placeholders × 1 year).
        $first = $exercises[0]->getExerciseDate();
        $last = $exercises[3]->getExerciseDate();
        self::assertNotNull($first);
        self::assertNotNull($last);

        $diffYears = ((int) $last->format('Y')) - ((int) $first->format('Y'));
        self::assertGreaterThanOrEqual(
            2,
            $diffYears,
            'annual cadence stretches the placeholders over ≥ 3 years',
        );

        // Audit-log records the cadence.
        self::assertCount(1, $this->auditEntries);
        self::assertSame(12, $this->auditEntries[0]['newValues']['cadence_months'] ?? null);
    }

    #[Test]
    public function auditLogIsWrittenOncePerSeed(): void
    {
        $seeder = $this->makeSeeder();
        $run = $this->makeRun(123);

        $seeder->seedExerciseProgramme($this->makeTenant(11), $run);

        self::assertCount(1, $this->auditEntries, 'one audit entry per seed');
        $entry = $this->auditEntries[0];
        self::assertSame('bcm_exercise_auto_seed', $entry['action']);
        self::assertSame('WizardRun', $entry['entityType']);
        self::assertSame(123, $entry['entityId']);
        self::assertSame(BcExerciseAutoSeeder::AUDIT_TAG, $entry['newValues']['tag'] ?? null);
        self::assertSame(11, $entry['newValues']['tenant_id'] ?? null);
        self::assertSame(4, $entry['newValues']['placeholder_count'] ?? null);
        self::assertSame(
            ['tabletop', 'walkthrough', 'simulation', 'full_test'],
            $entry['newValues']['types'] ?? null,
        );
    }
}
