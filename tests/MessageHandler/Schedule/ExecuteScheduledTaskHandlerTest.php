<?php

namespace App\Tests\MessageHandler\Schedule;

use App\Entity\ScheduledTask;
use App\Message\Schedule\ExecuteScheduledTaskMessage;
use App\MessageHandler\Schedule\ExecuteScheduledTaskHandler;
use App\Repository\ScheduledTaskRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ExecuteScheduledTaskHandlerTest extends TestCase
{
    private ExecuteScheduledTaskHandler $handler;
    private ScheduledTaskRepository $taskRepository;
    private EntityManagerInterface $em;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->taskRepository = $this->createMock(ScheduledTaskRepository::class);
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->handler = new ExecuteScheduledTaskHandler(
            $this->taskRepository,
            $this->em,
            $this->logger,
            '/var/www/project'
        );
    }

    public function testInvokeWithNonExistentTask(): void
    {
        $message = new ExecuteScheduledTaskMessage(999, new DateTimeImmutable());

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Scheduled task not found', ['task_id' => 999]);

        $this->em->expects($this->never())
            ->method('flush');

        ($this->handler)($message);
    }

    public function testInvokeWithDisabledTask(): void
    {
        $message = new ExecuteScheduledTaskMessage(1, new DateTimeImmutable());

        $task = $this->createMock(ScheduledTask::class);
        $task->method('getId')->willReturn(1);
        $task->method('getName')->willReturn('Test Task');
        $task->method('isEnabled')->willReturn(false);

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($task);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Scheduled task is disabled, skipping', $this->isType('array'));

        $this->em->expects($this->never())
            ->method('flush');

        ($this->handler)($message);
    }

    public function testInvokeWithEnabledTask(): void
    {
        $message = new ExecuteScheduledTaskMessage(1, new DateTimeImmutable());

        $task = $this->createMock(ScheduledTask::class);
        $task->method('getId')->willReturn(1);
        $task->method('getName')->willReturn('Test Task');
        $task->method('getCommand')->willReturn('app:test-command');
        $task->method('getArguments')->willReturn(['--verbose']);
        $task->method('isEnabled')->willReturn(true);

        $task->expects($this->once())
            ->method('setLastRunAt');

        $task->expects($this->atLeast(1))
            ->method('setLastStatus');

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($task);

        $this->em->expects($this->once())
            ->method('flush');

        ($this->handler)($message);
    }

    public function testInvokeWithTaskWithoutArguments(): void
    {
        $message = new ExecuteScheduledTaskMessage(1, new DateTimeImmutable());

        $task = $this->createMock(ScheduledTask::class);
        $task->method('getId')->willReturn(1);
        $task->method('getName')->willReturn('Test Task');
        $task->method('getCommand')->willReturn('app:simple-command');
        $task->method('getArguments')->willReturn(null);
        $task->method('isEnabled')->willReturn(true);

        $task->expects($this->once())
            ->method('setLastRunAt');

        $this->taskRepository->expects($this->once())
            ->method('find')
            ->with(1)
            ->willReturn($task);

        $this->em->expects($this->once())
            ->method('flush');

        ($this->handler)($message);
    }

    public function testMessageCreation(): void
    {
        $scheduledAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $message = new ExecuteScheduledTaskMessage(42, $scheduledAt);

        $this->assertSame(42, $message->getTaskId());
        $this->assertSame($scheduledAt, $message->getScheduledAt());
    }
}
