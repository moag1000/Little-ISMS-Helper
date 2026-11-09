<?php

declare(strict_types=1);

namespace App\Tests\Service\Job;

use App\Service\Job\WorkerHealthService;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Unit-tests WorkerHealthService::snapshot() classification logic.
 *
 * We mock KernelInterface to point at a tempdir for the heartbeat file
 * and var/jobs directory, and stub the DBAL Connection so queue-depth
 * queries return predictable values without an actual database.
 */
#[AllowMockObjectsWithoutExpectations]
final class WorkerHealthServiceTest extends TestCase
{
    private string $tmpDir;
    private string $heartbeatPath;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/worker-health-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir . '/var/jobs', 0775, true);
        $this->heartbeatPath = $this->tmpDir . '/var/jobs/.heartbeat';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->rrmdir($this->tmpDir);
        }
    }

    #[Test]
    public function unknownWhenNoHeartbeatAndEmptyQueue(): void
    {
        $svc = $this->makeService(queueDepth: 0);
        $snapshot = $svc->snapshot();

        self::assertSame(WorkerHealthService::STATUS_UNKNOWN, $snapshot['status']);
        self::assertNull($snapshot['heartbeat_at']);
        self::assertSame(0, $snapshot['queue_depth']);
    }

    #[Test]
    public function redWhenNoHeartbeatButQueueHasMessages(): void
    {
        $svc = $this->makeService(queueDepth: 12);
        $snapshot = $svc->snapshot();

        self::assertSame(WorkerHealthService::STATUS_RED, $snapshot['status']);
        self::assertSame('no_heartbeat_with_queue', $snapshot['reason']);
    }

    #[Test]
    public function greenWhenHeartbeatFresh(): void
    {
        $this->writeHeartbeat(time() - 5);
        $svc = $this->makeService(queueDepth: 3);
        $snapshot = $svc->snapshot();

        self::assertSame(WorkerHealthService::STATUS_GREEN, $snapshot['status']);
        self::assertLessThan(60, $snapshot['heartbeat_age_seconds']);
    }

    #[Test]
    public function yellowWhenHeartbeatMid(): void
    {
        $this->writeHeartbeat(time() - 120);
        $svc = $this->makeService(queueDepth: 5);
        $snapshot = $svc->snapshot();

        self::assertSame(WorkerHealthService::STATUS_YELLOW, $snapshot['status']);
    }

    #[Test]
    public function redWhenHeartbeatStale(): void
    {
        $this->writeHeartbeat(time() - 600);
        $svc = $this->makeService(queueDepth: 7);
        $snapshot = $svc->snapshot();

        self::assertSame(WorkerHealthService::STATUS_RED, $snapshot['status']);
        self::assertSame('heartbeat_stale', $snapshot['reason']);
    }

    #[Test]
    public function redReasonElevatedWhenStaleAndBacklog(): void
    {
        $this->writeHeartbeat(time() - 600);
        $svc = $this->makeService(queueDepth: 75);
        $snapshot = $svc->snapshot();

        self::assertSame(WorkerHealthService::STATUS_RED, $snapshot['status']);
        self::assertSame('heartbeat_stale_with_backlog', $snapshot['reason']);
    }

    #[Test]
    public function pendingJobCountReflectsVarJobsFiles(): void
    {
        file_put_contents(
            $this->tmpDir . '/var/jobs/00000000-0000-4000-8000-000000000001.json',
            json_encode(['status' => 'pending']),
        );
        file_put_contents(
            $this->tmpDir . '/var/jobs/00000000-0000-4000-8000-000000000002.json',
            json_encode(['status' => 'running']),
        );
        file_put_contents(
            $this->tmpDir . '/var/jobs/00000000-0000-4000-8000-000000000003.json',
            json_encode(['status' => 'pending']),
        );

        $svc = $this->makeService(queueDepth: 0);
        $snapshot = $svc->snapshot();

        self::assertSame(2, $snapshot['pending_jobs']);
    }

    #[Test]
    public function recordHeartbeatCreatesFile(): void
    {
        $svc = $this->makeService(queueDepth: 0);
        self::assertFileDoesNotExist($this->heartbeatPath);

        $svc->recordHeartbeat();

        self::assertFileExists($this->heartbeatPath);
    }

    #[Test]
    public function recentJobsAreSortedNewestFirst(): void
    {
        $now = time();
        $this->writeJobFile('aa', $now - 300);
        $this->writeJobFile('bb', $now - 100);
        $this->writeJobFile('cc', $now - 200);

        $svc = $this->makeService(queueDepth: 0);
        $recent = $svc->recentJobs(10);

        self::assertCount(3, $recent);
        self::assertSame('bb', $recent[0]['id']);
        self::assertSame('cc', $recent[1]['id']);
        self::assertSame('aa', $recent[2]['id']);
    }

    /** S13: an organisation admin sees only the jobs of the own organisation. */
    #[Test]
    public function recentJobsOfOneOrganisationOnly(): void
    {
        $now = time();
        $this->writeJobFile('own', $now - 100, 7);
        $this->writeJobFile('other', $now - 50, 8);
        $this->writeJobFile('operator', $now - 10);

        $recent = $this->makeService(queueDepth: 0)->recentJobs(10, ownerTenantId: 7);

        self::assertSame(['own'], array_column($recent, 'id'));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * A real messenger_messages table (SQLite): the queue name the Doctrine transport writes is
     * `default` when MESSENGER_TRANSPORT_DSN names none — a stubbed SQL string hid that the
     * service counted `async` and always saw 0.
     *
     * @param int $staleMails mails older than 15 minutes on top of $queueDepth fresh rows
     */
    private function makeService(int $queueDepth, int $staleMails = 0): WorkerHealthService
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->tmpDir);

        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE messenger_messages (id INTEGER PRIMARY KEY AUTOINCREMENT,
            body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL,
            created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL)');
        $insert = static function (string $queue, string $type, string $createdAt, ?string $deliveredAt = null) use ($connection): void {
            $connection->insert('messenger_messages', [
                'body' => '{}', 'headers' => json_encode(['type' => $type]), 'queue_name' => $queue,
                'created_at' => $createdAt, 'available_at' => $createdAt, 'delivered_at' => $deliveredAt,
            ]);
        };
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $old = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        for ($n = 0; $n < $queueDepth; ++$n) {
            $insert('default', 'App\\Message\\Job\\ExecuteJobMessage', $now);
        }
        for ($n = 0; $n < $staleMails; ++$n) {
            $insert('default', 'Symfony\\Component\\Mailer\\Messenger\\SendEmailMessage', $old);
        }
        // Never counted: other transports, and a row a worker is handling right now.
        $insert('scheduler', 'Symfony\\Component\\Mailer\\Messenger\\SendEmailMessage', $old);
        $insert('failed', 'Symfony\\Component\\Mailer\\Messenger\\SendEmailMessage', $old);
        $insert('default', 'Symfony\\Component\\Mailer\\Messenger\\SendEmailMessage', $old, $now);

        return new WorkerHealthService($kernel, $connection);
    }

    #[Test]
    public function theQueueDepthCountsTheTransportsDefaultQueue(): void
    {
        self::assertSame(3, $this->makeService(queueDepth: 3)->snapshot()['queue_depth']);
    }

    #[Test]
    public function mailsWaitingLongerThanFifteenMinutesAreRedEvenWithAFreshHeartbeat(): void
    {
        $this->writeHeartbeat(time() - 5);
        $snapshot = $this->makeService(queueDepth: 1, staleMails: 2)->snapshot();

        self::assertSame(WorkerHealthService::STATUS_RED, $snapshot['status']);
        self::assertSame('mails_stuck', $snapshot['reason']);
        self::assertSame(2, $snapshot['stale_mails']);
        self::assertSame(3, $snapshot['queue_depth']);
    }

    private function writeHeartbeat(int $mtime): void
    {
        touch($this->heartbeatPath, $mtime);
    }

    private function writeJobFile(string $id, int $mtime, ?int $ownerTenantId = null): void
    {
        $path = $this->tmpDir . '/var/jobs/' . $id . '.json';
        file_put_contents($path, json_encode(['id' => $id, 'status' => 'succeeded', 'owner_tenant_id' => $ownerTenantId]));
        touch($path, $mtime);
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
