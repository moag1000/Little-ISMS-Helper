<?php

namespace App\Tests\Service;

use App\Entity\ScheduledTask;
use App\Repository\ScheduledTaskRepository;
use App\Service\ScheduledTaskService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScheduledTaskServiceTest extends TestCase
{
    private MockObject $taskRepository;
    private MockObject $entityManager;
    private MockObject $tenantContext;
    private MockObject $logger;
    private ScheduledTaskService $service;

    protected function setUp(): void
    {
        $this->taskRepository = $this->createMock(ScheduledTaskRepository::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->tenantContext = $this->createMock(TenantContext::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new ScheduledTaskService(
            $this->taskRepository,
            $this->entityManager,
            $this->tenantContext,
            $this->logger
        );
    }

    // ========== createTask TESTS ==========

    public function testCreateTaskCreatesValidTask(): void
    {
        $this->tenantContext->method('getCurrentTenantId')->willReturn(1);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $task = $this->service->createTask(
            'Daily Backup',
            'app:backup',
            '0 2 * * *',  // Every day at 2am
            'Daily database backup'
        );

        $this->assertInstanceOf(ScheduledTask::class, $task);
        $this->assertSame('Daily Backup', $task->getName());
        $this->assertSame('app:backup', $task->getCommand());
        $this->assertSame('0 2 * * *', $task->getCronExpression());
        $this->assertSame('Daily database backup', $task->getDescription());
        $this->assertTrue($task->isEnabled());
        $this->assertNotNull($task->getNextRunAt());
    }

    public function testCreateTaskThrowsExceptionForInvalidCron(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cron expression');

        $this->service->createTask(
            'Invalid Task',
            'app:invalid',
            'not a valid cron expression'
        );
    }

    public function testCreateTaskWithArguments(): void
    {
        $this->tenantContext->method('getCurrentTenantId')->willReturn(1);

        $this->entityManager->expects($this->once())->method('persist');
        $this->entityManager->expects($this->once())->method('flush');

        $arguments = ['--force', '--verbose'];
        $task = $this->service->createTask(
            'Task with args',
            'app:command',
            '*/5 * * * *',
            null,
            $arguments
        );

        $this->assertSame($arguments, $task->getArguments());
    }

    // ========== updateTask TESTS ==========

    public function testUpdateTaskUpdatesName(): void
    {
        $task = $this->createTask();

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->updateTask($task, name: 'Updated Name');

        $this->assertSame('Updated Name', $result->getName());
    }

    public function testUpdateTaskUpdatesCommand(): void
    {
        $task = $this->createTask();

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->updateTask($task, command: 'app:new-command');

        $this->assertSame('app:new-command', $result->getCommand());
    }

    public function testUpdateTaskUpdatesCronExpression(): void
    {
        $task = $this->createTask();
        $originalNextRun = $task->getNextRunAt();

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->updateTask($task, cronExpression: '0 0 * * *'); // Midnight

        $this->assertSame('0 0 * * *', $result->getCronExpression());
        // Next run should be recalculated
        $this->assertNotEquals($originalNextRun, $result->getNextRunAt());
    }

    public function testUpdateTaskThrowsExceptionForInvalidCron(): void
    {
        $task = $this->createTask();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid cron expression');

        $this->service->updateTask($task, cronExpression: 'invalid');
    }

    public function testUpdateTaskUpdatesDescription(): void
    {
        $task = $this->createTask();

        $this->entityManager->expects($this->once())->method('flush');

        $result = $this->service->updateTask($task, description: 'New description');

        $this->assertSame('New description', $result->getDescription());
    }

    public function testUpdateTaskUpdatesArguments(): void
    {
        $task = $this->createTask();

        $this->entityManager->expects($this->once())->method('flush');

        $newArgs = ['--dry-run'];
        $result = $this->service->updateTask($task, arguments: $newArgs);

        $this->assertSame($newArgs, $result->getArguments());
    }

    public function testUpdateTaskPartialUpdate(): void
    {
        $task = $this->createTask();
        $originalCommand = $task->getCommand();

        $this->entityManager->expects($this->once())->method('flush');

        // Only update name, keep other fields
        $result = $this->service->updateTask($task, name: 'Only Name Changed');

        $this->assertSame('Only Name Changed', $result->getName());
        $this->assertSame($originalCommand, $result->getCommand());
    }

    // ========== toggleTask TESTS ==========

    public function testToggleTaskEnables(): void
    {
        $task = $this->createTask();
        $task->setEnabled(false);

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->toggleTask($task, true);

        $this->assertTrue($task->isEnabled());
    }

    public function testToggleTaskDisables(): void
    {
        $task = $this->createTask();
        $task->setEnabled(true);

        $this->entityManager->expects($this->once())->method('flush');

        $this->service->toggleTask($task, false);

        $this->assertFalse($task->isEnabled());
    }

    // ========== deleteTask TESTS ==========

    public function testDeleteTaskRemovesTask(): void
    {
        $task = $this->createTask();

        $this->entityManager->expects($this->once())
            ->method('remove')
            ->with($task);
        $this->entityManager->expects($this->once())->method('flush');

        $this->service->deleteTask($task);
    }

    // ========== validateCronExpression TESTS ==========

    public function testValidateCronExpressionReturnsTrue(): void
    {
        $this->assertTrue($this->service->validateCronExpression('* * * * *'));
        $this->assertTrue($this->service->validateCronExpression('0 0 * * *'));
        $this->assertTrue($this->service->validateCronExpression('*/5 * * * *'));
        $this->assertTrue($this->service->validateCronExpression('0 2 * * MON'));
        $this->assertTrue($this->service->validateCronExpression('0 0 1 * *'));
    }

    public function testValidateCronExpressionReturnsFalse(): void
    {
        $this->assertFalse($this->service->validateCronExpression('invalid'));
        $this->assertFalse($this->service->validateCronExpression('* * *'));
        $this->assertFalse($this->service->validateCronExpression('60 * * * *'));
        $this->assertFalse($this->service->validateCronExpression(''));
    }

    // ========== describeCronExpression TESTS ==========

    public function testDescribeCronExpressionValid(): void
    {
        $result = $this->service->describeCronExpression('0 0 * * *');

        $this->assertStringContainsString('Next run:', $result);
    }

    public function testDescribeCronExpressionInvalid(): void
    {
        $result = $this->service->describeCronExpression('invalid');

        $this->assertSame('Invalid cron expression', $result);
    }

    // ========== getTasksForCurrentTenant TESTS ==========

    public function testGetTasksForCurrentTenantFilters(): void
    {
        $this->tenantContext->method('getCurrentTenantId')->willReturn(42);

        $tasks = [
            $this->createTask(),
            $this->createTask(),
        ];

        $this->taskRepository->method('findBy')
            ->with(['tenantId' => 42])
            ->willReturn($tasks);

        $result = $this->service->getTasksForCurrentTenant();

        $this->assertCount(2, $result);
    }

    // ========== getStatistics TESTS ==========

    public function testGetStatisticsReturnsAllKeys(): void
    {
        $this->tenantContext->method('getCurrentTenantId')->willReturn(1);
        $this->taskRepository->method('findBy')->willReturn([]);

        $result = $this->service->getStatistics();

        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('enabled', $result);
        $this->assertArrayHasKey('disabled', $result);
        $this->assertArrayHasKey('last_success', $result);
        $this->assertArrayHasKey('last_failed', $result);
        $this->assertArrayHasKey('running', $result);
    }

    public function testGetStatisticsCountsCorrectly(): void
    {
        $this->tenantContext->method('getCurrentTenantId')->willReturn(1);

        $enabledSuccess = $this->createMock(ScheduledTask::class);
        $enabledSuccess->method('isEnabled')->willReturn(true);
        $enabledSuccess->method('getLastStatus')->willReturn('success');

        $enabledFailed = $this->createMock(ScheduledTask::class);
        $enabledFailed->method('isEnabled')->willReturn(true);
        $enabledFailed->method('getLastStatus')->willReturn('failed');

        $disabledRunning = $this->createMock(ScheduledTask::class);
        $disabledRunning->method('isEnabled')->willReturn(false);
        $disabledRunning->method('getLastStatus')->willReturn('running');

        $enabledNoStatus = $this->createMock(ScheduledTask::class);
        $enabledNoStatus->method('isEnabled')->willReturn(true);
        $enabledNoStatus->method('getLastStatus')->willReturn(null);

        $this->taskRepository->method('findBy')
            ->willReturn([$enabledSuccess, $enabledFailed, $disabledRunning, $enabledNoStatus]);

        $result = $this->service->getStatistics();

        $this->assertSame(4, $result['total']);
        $this->assertSame(3, $result['enabled']);
        $this->assertSame(1, $result['disabled']);
        $this->assertSame(1, $result['last_success']);
        $this->assertSame(1, $result['last_failed']);
        $this->assertSame(1, $result['running']);
    }

    public function testGetStatisticsEmptyWhenNoTasks(): void
    {
        $this->tenantContext->method('getCurrentTenantId')->willReturn(1);
        $this->taskRepository->method('findBy')->willReturn([]);

        $result = $this->service->getStatistics();

        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['enabled']);
        $this->assertSame(0, $result['disabled']);
    }

    // ========== Helper Methods ==========

    private function createTask(): ScheduledTask
    {
        $task = new ScheduledTask();
        $task->setName('Test Task');
        $task->setCommand('app:test');
        $task->setCronExpression('*/15 * * * *');
        $task->setEnabled(true);
        $task->setTenantId(1);

        // Set next run
        $cron = new \Cron\CronExpression('*/15 * * * *');
        $task->setNextRunAt($cron->getNextRunDate());

        return $task;
    }
}
