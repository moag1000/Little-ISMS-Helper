<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ProcessTimedWorkflowsCommand;
use App\Repository\Notification\NotificationRuleRepository;
use App\Repository\Notification\SlaDeadlineMonitorRepository;
use App\Repository\TenantRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\AuditLogger;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\SlaDeadlineMonitorRetry;
use App\Service\Notification\SlaDeadlineWatcher;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use App\Doctrine\EntityManagerRecovery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * #1059 review I-2: the SLA spawn retry is best effort. When it throws (and
 * closes the EntityManager), the deadline watcher must still tick — GDPR,
 * NIS2 and DORA notifications must not stop over one unstorable row. The
 * watcher also ticks when no workflow is active (the command used to return
 * before reaching it).
 */
final class ProcessTimedWorkflowsSlaRetryTest extends TestCase
{
    #[Test]
    public function failingRetryStillLetsTheWatcherTickAfterResettingTheEntityManager(): void
    {
        $retry = $this->createStub(SlaDeadlineMonitorRetry::class);
        $retry->method('spawnMissing')->willThrowException(new \RuntimeException('monitor row rejected'));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(false); // the failed flush closed it

        $registry = $this->createMock(EntityManagerRecovery::class);
        $registry->expects(self::once())->method('reset');

        $tenants = $this->createMock(TenantRepository::class);
        $tenants->expects(self::once())->method('findActive')->willReturn([]); // = tickAll() ran

        $watcher = new SlaDeadlineWatcher(
            $tenants,
            $this->createStub(SlaDeadlineMonitorRepository::class),
            $this->createStub(NotificationRuleRepository::class),
            $this->createStub(NotificationDispatcher::class),
            $em,
            $this->createStub(AuditLogger::class),
            new NullLogger(),
        );
        $workflows = $this->createStub(WorkflowInstanceRepository::class);
        $workflows->method('findBy')->willReturn([]);

        $tester = new CommandTester(new ProcessTimedWorkflowsCommand(
            $workflows,
            $this->createStub(WorkflowService::class),
            $em,
            $watcher,
            null,
            $retry,
            $registry,
        ));
        $code = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $code);
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('monitor row rejected', $display);
        self::assertStringContainsString('SLA deadline watcher completed', $display);
    }
}
