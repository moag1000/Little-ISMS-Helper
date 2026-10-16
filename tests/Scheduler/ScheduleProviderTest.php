<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Entity\ScheduledTask;
use App\Repository\ScheduledTaskRepository;
use App\Schedule;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * The schedule provider used to call the protected
 * Repository::getEntityManager() — an \Error that escaped catch (Exception)
 * and took the whole provider (built-in tasks included) down.
 */
final class ScheduleProviderTest extends TestCase
{
    private const BUILT_IN_TASKS = 5;

    #[Test]
    public function databaseTasksAreAddedNextToTheBuiltInTasks(): void
    {
        $task = $this->createStub(ScheduledTask::class);
        $task->method('getId')->willReturn(42);
        $task->method('getName')->willReturn('nightly');
        $task->method('getCronExpression')->willReturn('0 3 * * *');

        $repository = $this->createStub(ScheduledTaskRepository::class);
        $repository->method('findEnabled')->willReturn([$task]);

        $schedule = $this->provider($repository, ['scheduled_task'])->getSchedule();

        self::assertCount(self::BUILT_IN_TASKS + 1, $schedule->getRecurringMessages());
    }

    #[Test]
    public function missingTableKeepsTheBuiltInTasks(): void
    {
        $repository = $this->createMock(ScheduledTaskRepository::class);
        $repository->expects(self::never())->method('findEnabled');

        $schedule = $this->provider($repository, [])->getSchedule();

        self::assertCount(self::BUILT_IN_TASKS, $schedule->getRecurringMessages());
    }

    #[Test]
    public function brokenTaskDataDoesNotKillTheProvider(): void
    {
        $repository = $this->createStub(ScheduledTaskRepository::class);
        $repository->method('findEnabled')->willThrowException(new \TypeError('corrupt row'));

        $schedule = $this->provider($repository, ['scheduled_task'])->getSchedule();

        self::assertCount(self::BUILT_IN_TASKS, $schedule->getRecurringMessages());
    }

    /**
     * @param list<string> $tables
     */
    private function provider(ScheduledTaskRepository $repository, array $tables): Schedule
    {
        $schemaManager = $this->createStub(AbstractSchemaManager::class);
        $schemaManager->method('listTableNames')->willReturn($tables);

        $connection = $this->createStub(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schemaManager);

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);

        return new Schedule($this->createStub(CacheInterface::class), $repository, new NullLogger(), $entityManager);
    }
}
