<?php

declare(strict_types=1);

namespace App\Tests\Service\Job;

use App\Service\Job\WorkerHealthService;
use Doctrine\DBAL\Connection;
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

    // ── helpers ───────────────────────────────────────────────────────────

    private function makeService(int $queueDepth): WorkerHealthService
    {
        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->tmpDir);

        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturnCallback(
            static function (string $sql) use ($queueDepth): int {
                if (str_contains($sql, "queue_name = 'async'")) {
                    return $queueDepth;
                }
                // failed-queue lookup
                return 0;
            },
        );

        return new WorkerHealthService($kernel, $connection);
    }

    private function writeHeartbeat(int $mtime): void
    {
        touch($this->heartbeatPath, $mtime);
    }

    private function writeJobFile(string $id, int $mtime): void
    {
        $path = $this->tmpDir . '/var/jobs/' . $id . '.json';
        file_put_contents($path, json_encode(['id' => $id, 'status' => 'succeeded']));
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
