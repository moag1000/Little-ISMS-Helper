<?php

namespace App\Tests\Service;

use App\Entity\ISMSObjective;
use App\Repository\ISMSObjectiveRepository;
use App\Service\ISMSObjectiveService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class ISMSObjectiveServiceTest extends TestCase
{
    private MockObject $objectiveRepository;
    private MockObject $entityManager;
    private ISMSObjectiveService $service;

    protected function setUp(): void
    {
        $this->objectiveRepository = $this->createMock(ISMSObjectiveRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $this->service = new ISMSObjectiveService(
            $this->objectiveRepository,
            $this->entityManager
        );
    }

    #[Test]
    public function testGetStatisticsWithNoObjectives(): void
    {
        $this->objectiveRepository->method('findAll')->willReturn([]);
        $this->objectiveRepository->method('findActive')->willReturn([]);
        $this->objectiveRepository->method('findBy')->willReturn([]);

        $stats = $this->service->getStatistics();

        $this->assertSame(0, $stats['total']);
        $this->assertSame(0, $stats['active']);
        $this->assertSame(0, $stats['achieved']);
        $this->assertSame(0, $stats['delayed']);
        $this->assertSame(0, $stats['at_risk']);
    }

    #[Test]
    public function testGetStatisticsWithMixedObjectives(): void
    {
        $achieved = $this->createObjective('achieved', new \DateTime('+30 days'));
        $inProgress = $this->createObjective('in_progress', new \DateTime('+60 days'));
        $delayed = $this->createObjective('in_progress', new \DateTime('-10 days'));
        $atRisk = $this->createObjective('in_progress', new \DateTime('+15 days'));

        $allObjectives = [$achieved, $inProgress, $delayed, $atRisk];

        $this->objectiveRepository->method('findAll')->willReturn($allObjectives);
        $this->objectiveRepository->method('findActive')->willReturn([$inProgress, $delayed, $atRisk]);
        $this->objectiveRepository->method('findBy')
            ->with(['status' => 'achieved'])
            ->willReturn([$achieved]);

        $stats = $this->service->getStatistics();

        $this->assertSame(4, $stats['total']);
        $this->assertSame(1, $stats['achieved']);
        $this->assertSame(1, $stats['delayed']);
        $this->assertSame(1, $stats['at_risk']); // +15 days is within 30 days
    }

    #[Test]
    public function testGetOverdueObjectives(): void
    {
        $overdue = $this->createObjective('in_progress', new \DateTime('-5 days'));
        $onTrack = $this->createObjective('in_progress', new \DateTime('+10 days'));
        $future = $this->createObjective('in_progress', new \DateTime('+60 days'));

        $this->objectiveRepository->method('findActive')
            ->willReturn([$overdue, $onTrack, $future]);

        $overdueObjectives = $this->service->getOverdueObjectives();

        $this->assertCount(1, $overdueObjectives);
        $this->assertSame($overdue, reset($overdueObjectives));
    }

    #[Test]
    public function testGetAtRiskObjectives(): void
    {
        $atRisk = $this->createObjective('in_progress', new \DateTime('+15 days'));
        $safe = $this->createObjective('in_progress', new \DateTime('+45 days'));
        $overdue = $this->createObjective('in_progress', new \DateTime('-5 days'));

        $this->objectiveRepository->method('findActive')
            ->willReturn([$atRisk, $safe, $overdue]);

        $atRiskObjectives = $this->service->getAtRiskObjectives();

        $this->assertCount(1, $atRiskObjectives);
        $this->assertSame($atRisk, reset($atRiskObjectives));
    }

    #[Test]
    public function testCalculateOverallProgressWithNoObjectives(): void
    {
        $this->objectiveRepository->method('findAll')->willReturn([]);

        $progress = $this->service->calculateOverallProgress();

        $this->assertSame(0.0, $progress);
    }

    #[Test]
    public function testCalculateOverallProgressWithObjectives(): void
    {
        $obj1 = $this->createObjectiveWithProgress(50);
        $obj2 = $this->createObjectiveWithProgress(100);
        $obj3 = $this->createObjectiveWithProgress(75);

        $this->objectiveRepository->method('findAll')
            ->willReturn([$obj1, $obj2, $obj3]);

        $progress = $this->service->calculateOverallProgress();

        // (50 + 100 + 75) / 3 = 75
        $this->assertSame(75.0, $progress);
    }

    #[Test]
    public function testCalculateOverallProgressIgnoresObjectivesWithoutValues(): void
    {
        $withProgress = $this->createObjectiveWithProgress(80);
        $withoutTarget = $this->createObjectiveWithoutValues();

        $this->objectiveRepository->method('findAll')
            ->willReturn([$withProgress, $withoutTarget]);

        $progress = $this->service->calculateOverallProgress();

        // Only counts the one with values
        $this->assertSame(80.0, $progress);
    }

    #[Test]
    public function testGetObjectivesByCategory(): void
    {
        $securityObjectives = [
            $this->createObjective('in_progress', new \DateTime('+30 days')),
            $this->createObjective('achieved', new \DateTime('+60 days')),
        ];

        $this->objectiveRepository->method('findBy')
            ->with(['category' => 'security'])
            ->willReturn($securityObjectives);

        $result = $this->service->getObjectivesByCategory('security');

        $this->assertCount(2, $result);
        $this->assertSame($securityObjectives, $result);
    }

    #[Test]
    public function testUpdateObjectiveSetsUpdatedAt(): void
    {
        $objective = $this->createMock(ISMSObjective::class);
        $objective->method('getStatus')->willReturn('in_progress');

        $objective->expects($this->once())
            ->method('setUpdatedAt')
            ->with($this->isInstanceOf(\DateTimeInterface::class));

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->service->updateObjective($objective);
    }

    #[Test]
    public function testUpdateObjectiveSetsAchievedDateWhenStatusIsAchieved(): void
    {
        $objective = $this->createMock(ISMSObjective::class);
        $objective->method('getStatus')->willReturn('achieved');
        $objective->method('getAchievedDate')->willReturn(null);

        $objective->expects($this->once())
            ->method('setUpdatedAt')
            ->with($this->isInstanceOf(\DateTimeInterface::class));

        $objective->expects($this->once())
            ->method('setAchievedDate')
            ->with($this->isInstanceOf(\DateTimeInterface::class));

        $this->service->updateObjective($objective);
    }

    #[Test]
    public function testUpdateObjectiveDoesNotOverwriteExistingAchievedDate(): void
    {
        $existingDate = new \DateTime('-10 days');
        $objective = $this->createMock(ISMSObjective::class);
        $objective->method('getStatus')->willReturn('achieved');
        $objective->method('getAchievedDate')->willReturn($existingDate);

        $objective->expects($this->once())
            ->method('setUpdatedAt');

        // Should NOT call setAchievedDate since it already has one
        $objective->expects($this->never())
            ->method('setAchievedDate');

        $this->service->updateObjective($objective);
    }

    #[Test]
    public function testCreateObjectivePersistsAndFlushes(): void
    {
        $objective = $this->createMock(ISMSObjective::class);

        $objective->expects($this->once())
            ->method('setUpdatedAt')
            ->with($this->isInstanceOf(\DateTimeInterface::class));

        $this->entityManager->expects($this->once())
            ->method('persist')
            ->with($objective);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->service->createObjective($objective);
    }

    #[Test]
    public function testDeleteObjectiveRemovesAndFlushes(): void
    {
        $objective = $this->createMock(ISMSObjective::class);

        $this->entityManager->expects($this->once())
            ->method('remove')
            ->with($objective);

        $this->entityManager->expects($this->once())
            ->method('flush');

        $this->service->deleteObjective($objective);
    }

    #[Test]
    public function testAtRiskCountExcludesOverdueObjectives(): void
    {
        // Overdue objective should not be counted as "at risk"
        $overdue = $this->createObjective('in_progress', new \DateTime('-5 days'));
        $atRisk = $this->createObjective('in_progress', new \DateTime('+20 days'));

        $this->objectiveRepository->method('findAll')->willReturn([$overdue, $atRisk]);
        $this->objectiveRepository->method('findActive')->willReturn([$overdue, $atRisk]);
        $this->objectiveRepository->method('findBy')->willReturn([]);

        $stats = $this->service->getStatistics();

        $this->assertSame(1, $stats['at_risk']); // Only atRisk, not overdue
        $this->assertSame(1, $stats['delayed']); // Overdue is delayed
    }

    #[Test]
    public function testMultipleDelayedObjectives(): void
    {
        $delayed1 = $this->createObjective('in_progress', new \DateTime('-10 days'));
        $delayed2 = $this->createObjective('in_progress', new \DateTime('-5 days'));
        $onTrack = $this->createObjective('in_progress', new \DateTime('+30 days'));

        $this->objectiveRepository->method('findAll')->willReturn([$delayed1, $delayed2, $onTrack]);
        $this->objectiveRepository->method('findActive')->willReturn([$delayed1, $delayed2, $onTrack]);
        $this->objectiveRepository->method('findBy')->willReturn([]);

        $stats = $this->service->getStatistics();

        $this->assertSame(2, $stats['delayed']);
    }

    private function createObjective(string $status, \DateTime $targetDate): MockObject
    {
        $objective = $this->createMock(ISMSObjective::class);
        $objective->method('getStatus')->willReturn($status);
        $objective->method('getTargetDate')->willReturn($targetDate);
        $objective->method('getAchievedDate')->willReturn($status === 'achieved' ? new \DateTime() : null);
        return $objective;
    }

    private function createObjectiveWithProgress(int $progressPercentage): MockObject
    {
        $objective = $this->createMock(ISMSObjective::class);
        $objective->method('getTargetValue')->willReturn('100');
        $objective->method('getCurrentValue')->willReturn('50');
        $objective->method('getProgressPercentage')->willReturn($progressPercentage);
        return $objective;
    }

    private function createObjectiveWithoutValues(): MockObject
    {
        $objective = $this->createMock(ISMSObjective::class);
        $objective->method('getTargetValue')->willReturn(null);
        $objective->method('getCurrentValue')->willReturn(null);
        return $objective;
    }
}
