<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\BCExercise;
use App\Entity\Bsi2004ExerciseLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\Bsi2004ExerciseLogService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[AllowMockObjectsWithoutExpectations]
class Bsi2004ExerciseLogServiceTest extends TestCase
{
    private MockObject $em;
    private MockObject $auditLogger;
    private Bsi2004ExerciseLogService $service;
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->em          = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->service     = new Bsi2004ExerciseLogService($this->em, $this->auditLogger);
        $this->tenant      = new Tenant();
    }

    // -------------------------------------------------------------------------
    // createFromExercise
    // -------------------------------------------------------------------------

    #[Test]
    public function createFromExercisePreFillsScenarioFromDescription(): void
    {
        $exercise = $this->makeExercise('tabletop', 'Fire drill', 'Described scenario');
        $exercise->setObjectives("Objective A\nObjective B");
        $exercise->setParticipants('Alice, Bob');

        $log = $this->service->createFromExercise($exercise);

        self::assertSame('Described scenario', $log->getScenarioSummary());
        self::assertSame(['Objective A', 'Objective B'], $log->getObjectives());
        self::assertCount(2, $log->getParticipants());
        self::assertSame('Alice', $log->getParticipants()[0]['name']);
    }

    #[Test]
    public function createFromExerciseFallsBackToDescriptionWhenNoScenario(): void
    {
        $exercise = $this->makeExercise('tabletop', 'Drill', null);
        $exercise->setDescription('Exercise description');
        $exercise->setObjectives('');
        $exercise->setParticipants('');

        $log = $this->service->createFromExercise($exercise);

        self::assertSame('Exercise description', $log->getScenarioSummary());
        self::assertSame([], $log->getObjectives());
        self::assertSame([], $log->getParticipants());
    }

    #[Test]
    public function createFromExerciseMapsExerciseTypesCorrectly(): void
    {
        $exercise = $this->makeExercise('full_test', 'Test', null);
        $exercise->setObjectives('');
        $exercise->setParticipants('');

        $log = $this->service->createFromExercise($exercise);
        self::assertSame(Bsi2004ExerciseLog::EXERCISE_TYPE_FULL_SCALE, $log->getExerciseType());
    }

    #[Test]
    public function createFromExerciseThrowsWhenLogAlreadyExists(): void
    {
        $exercise = $this->makeExercise('tabletop', 'Test', null);
        $exercise->setObjectives('');
        $exercise->setParticipants('');

        $existingLog = new Bsi2004ExerciseLog();
        $existingLog->setBcExercise($exercise);
        $exercise->setExerciseLog($existingLog);

        $this->expectException(RuntimeException::class);
        $this->service->createFromExercise($exercise);
    }

    // -------------------------------------------------------------------------
    // markComplete
    // -------------------------------------------------------------------------

    #[Test]
    public function markCompleteTransitionsToSubmitted(): void
    {
        $log  = new Bsi2004ExerciseLog();
        $log->setBcExercise($this->makeExercise('tabletop', 'Test', null));
        $user = new User();

        $this->em->expects(self::once())->method('flush');
        $this->auditLogger->expects(self::once())->method('logCustom');

        $this->service->markComplete($log, $user);

        self::assertTrue($log->isSubmitted());
        self::assertNotNull($log->getSubmittedAt());
        self::assertSame($user, $log->getSubmittedBy());
    }

    #[Test]
    public function markCompleteThrowsWhenAlreadySubmitted(): void
    {
        $log = new Bsi2004ExerciseLog();
        $log->setSubmittedAt(new \DateTimeImmutable());

        $this->expectException(RuntimeException::class);
        $this->service->markComplete($log, new User());
    }

    // -------------------------------------------------------------------------
    // confirmByAuditor
    // -------------------------------------------------------------------------

    #[Test]
    public function confirmByAuditorTransitionsToConfirmed(): void
    {
        $log = new Bsi2004ExerciseLog();
        $log->setBcExercise($this->makeExercise('tabletop', 'Test', null));
        $log->setSubmittedAt(new \DateTimeImmutable());
        $auditor = new User();

        $this->em->expects(self::once())->method('flush');
        $this->auditLogger->expects(self::once())->method('logCustom');

        $this->service->confirmByAuditor($log, $auditor);

        self::assertTrue($log->isConfirmed());
        self::assertSame($auditor, $log->getConfirmedByAuditor());
    }

    #[Test]
    public function confirmByAuditorThrowsWhenNotSubmitted(): void
    {
        $log = new Bsi2004ExerciseLog();

        $this->expectException(RuntimeException::class);
        $this->service->confirmByAuditor($log, new User());
    }

    #[Test]
    public function confirmByAuditorThrowsWhenAlreadyConfirmed(): void
    {
        $log = new Bsi2004ExerciseLog();
        $log->setSubmittedAt(new \DateTimeImmutable());
        $log->setConfirmedAt(new \DateTimeImmutable());

        $this->expectException(RuntimeException::class);
        $this->service->confirmByAuditor($log, new User());
    }

    // -------------------------------------------------------------------------
    // extractImprovementActionsAsTasks
    // -------------------------------------------------------------------------

    #[Test]
    public function extractImprovementActionsFiltersOutCompletedItems(): void
    {
        $log = new Bsi2004ExerciseLog();
        $log->setImprovementActions([
            ['description' => 'Open action',     'completed' => false],
            ['description' => 'Closed action',   'completed' => true],
            ['description' => 'Another open',    'completed' => false],
            ['description' => '',                'completed' => false], // empty, should be filtered
        ]);

        $tasks = $this->service->extractImprovementActionsAsTasks($log);
        self::assertCount(2, $tasks);
        self::assertSame('Open action', $tasks[0]['description']);
        self::assertSame('Another open', $tasks[1]['description']);
    }

    #[Test]
    public function extractImprovementActionsReturnsEmptyWhenNull(): void
    {
        $log = new Bsi2004ExerciseLog();
        self::assertSame([], $this->service->extractImprovementActionsAsTasks($log));
    }

    // -------------------------------------------------------------------------

    private function makeExercise(string $type, string $name, ?string $scenario): BCExercise
    {
        $exercise = new BCExercise();
        $exercise->setExerciseType($type);
        $exercise->setName($name);
        $exercise->setScenario($scenario);
        $exercise->setTenant($this->tenant);
        $exercise->setScope('scope');
        $exercise->setFacilitator('facilitator');
        return $exercise;
    }
}
