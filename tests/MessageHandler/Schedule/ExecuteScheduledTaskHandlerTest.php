<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler\Schedule;

use App\Entity\ScheduledTask;
use App\Message\Schedule\ExecuteScheduledTaskMessage;
use App\MessageHandler\Schedule\ExecuteScheduledTaskHandler;
use App\Repository\ScheduledTaskRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
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

    #[Test]
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

    #[Test]
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
            ->with('Scheduled task is disabled, skipping', $this->isArray());

        $this->em->expects($this->never())
            ->method('flush');

        ($this->handler)($message);
    }

    #[Test]
    public function testInvokeWithEnabledTask(): void
    {
        $message = new ExecuteScheduledTaskMessage(1, new DateTimeImmutable());

        $task = $this->createMock(ScheduledTask::class);
        $task->method('getId')->willReturn(1);
        $task->method('getName')->willReturn('Test Task');
        $task->method('getCommand')->willReturn('app:process-timed-workflows');
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

    #[Test]
    public function testInvokeWithTaskWithoutArguments(): void
    {
        $message = new ExecuteScheduledTaskMessage(1, new DateTimeImmutable());

        $task = $this->createMock(ScheduledTask::class);
        $task->method('getId')->willReturn(1);
        $task->method('getName')->willReturn('Test Task');
        $task->method('getCommand')->willReturn('app:send-reminders');
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

    /**
     * @return iterable<string, array{string, list<string>, list<string>, list<string>}>
     */
    public static function argumentCases(): iterable
    {
        yield 'bare -e drops its value' => ['app:send-reminders', ['-e', 'prod', '-v'], ['-v'], ['-e', 'prod']];
        yield 'glued -eprod' => ['app:send-reminders', ['-eprod'], [], ['-eprod']];
        yield '-e with space value' => ['app:send-reminders', ['-e prod'], [], ['-e prod']];
        yield '--env=prod' => ['app:send-reminders', ['--env=prod'], [], ['--env=prod']];
        yield 'bare --env drops its value' => ['app:send-reminders', ['--env', 'prod', '--dry-run'], ['--dry-run'], ['--env', 'prod']];
        yield '--environment prefix' => ['app:send-reminders', ['--environment=dev'], [], ['--environment=dev']];
        yield '--kernel-class' => ['app:send-reminders', ['--kernel-class=Foo'], [], ['--kernel-class=Foo']];
        yield 'harmless options stay' => ['app:backup:prune', ['--keep=5', '-v', '--no-interaction'], ['--keep=5', '-v', '--no-interaction'], []];
        yield 'positional args of other commands stay' => ['app:generate-regulatory-workflows', ['data-breach'], ['data-breach'], []];
        yield 'migrate refuses a version argument' => ['doctrine:migrations:migrate', ['first', '--no-interaction'], ['--no-interaction'], ['first']];
        yield 'migrate refuses prev and a separator' => ['doctrine:migrations:migrate', ['--', 'prev'], [], ['--', 'prev']];
    }

    /**
     * @param list<string> $arguments
     * @param list<string> $expectedAllowed
     * @param list<string> $expectedBlocked
     */
    #[Test]
    #[DataProvider('argumentCases')]
    public function filterArgumentsBlocksEnvironmentSwitchesAndMigrationVersions(
        string $command,
        array $arguments,
        array $expectedAllowed,
        array $expectedBlocked,
    ): void {
        [$allowed, $blocked] = ExecuteScheduledTaskHandler::filterArguments($command, $arguments);

        self::assertSame($expectedAllowed, $allowed);
        self::assertSame($expectedBlocked, $blocked);
    }

    #[Test]
    public function testMessageCreation(): void
    {
        $scheduledAt = new DateTimeImmutable('2024-01-15 10:00:00');
        $message = new ExecuteScheduledTaskMessage(42, $scheduledAt);

        $this->assertSame(42, $message->getTaskId());
        $this->assertSame($scheduledAt, $message->getScheduledAt());
    }
}
