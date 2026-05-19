<?php

declare(strict_types=1);

namespace App\Service\Job;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Diagnoses the async-job worker on shared-hosting deployments.
 *
 * Three signals feed a tri-state traffic light (GREEN/YELLOW/RED):
 *  1. Heartbeat file `var/jobs/.heartbeat` — updated by HeartbeatMiddleware
 *     after every successfully handled async message
 *  2. Queue depth — `COUNT(*) FROM messenger_messages WHERE queue_name='async'`
 *  3. Pending job count — `var/jobs/*.json` with status='pending'
 *
 * Designed for shared-hosting context (1-minute cron slot). Thresholds:
 *  - GREEN: heartbeat <60s old
 *  - YELLOW: heartbeat 60–300s old (cron slow, eventually drains)
 *  - RED: heartbeat >300s old OR (queue depth >50 AND no heartbeat <300s)
 *
 * No Doctrine ORM — pure DBAL to avoid hydration cost on every page render.
 */
final class WorkerHealthService
{
    public const STATUS_GREEN = 'green';
    public const STATUS_YELLOW = 'yellow';
    public const STATUS_RED = 'red';
    public const STATUS_UNKNOWN = 'unknown';

    private const HEARTBEAT_FILE = '.heartbeat';
    private const GREEN_THRESHOLD_SECONDS = 60;
    private const YELLOW_THRESHOLD_SECONDS = 300;
    private const RED_QUEUE_DEPTH_THRESHOLD = 50;
    private const FAILED_LOOKBACK_SECONDS = 86400;
    private const RECENT_JOBS_LIMIT = 20;

    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Returns a structured health snapshot suitable for both the UI page
     * and the always-on banner check.
     *
     * @return array{
     *     status: 'green'|'yellow'|'red'|'unknown',
     *     queue_depth: int,
     *     pending_jobs: int,
     *     failed_jobs_24h: int,
     *     heartbeat_at: ?int,
     *     heartbeat_age_seconds: ?int,
     *     reason: string,
     * }
     */
    public function snapshot(): array
    {
        $heartbeatAt = $this->readHeartbeat();
        $heartbeatAge = $heartbeatAt !== null ? time() - $heartbeatAt : null;
        $queueDepth = $this->queueDepth();
        $pendingJobs = $this->pendingJobCount();
        $failed24h = $this->failedJobs24h();

        [$status, $reason] = $this->classify($heartbeatAge, $queueDepth);

        return [
            'status' => $status,
            'queue_depth' => $queueDepth,
            'pending_jobs' => $pendingJobs,
            'failed_jobs_24h' => $failed24h,
            'heartbeat_at' => $heartbeatAt,
            'heartbeat_age_seconds' => $heartbeatAge,
            'reason' => $reason,
        ];
    }

    /**
     * Touches the heartbeat file. Called by HeartbeatMiddleware after every
     * successfully handled async-job message. Cheap — no Doctrine roundtrip.
     */
    public function recordHeartbeat(): void
    {
        $path = $this->heartbeatPath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @touch($path);
    }

    /**
     * Returns at most RECENT_JOBS_LIMIT job-status snapshots, newest first,
     * read from `var/jobs/*.json`.
     *
     * @return list<array<string,mixed>>
     */
    public function recentJobs(int $limit = self::RECENT_JOBS_LIMIT): array
    {
        $dir = $this->kernel->getProjectDir() . '/var/jobs';
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/*.json') ?: [];
        // Sort newest-first by mtime
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $files = array_slice($files, 0, $limit);

        $out = [];
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (!is_array($data)) {
                continue;
            }
            $out[] = $data;
        }
        return $out;
    }

    private function classify(?int $heartbeatAge, int $queueDepth): array
    {
        if ($heartbeatAge === null) {
            // No heartbeat ever recorded. If there's nothing in the queue
            // either, this is just a fresh install — UNKNOWN rather than RED.
            if ($queueDepth === 0) {
                return [self::STATUS_UNKNOWN, 'no_heartbeat_yet'];
            }
            return [self::STATUS_RED, 'no_heartbeat_with_queue'];
        }

        if ($heartbeatAge < self::GREEN_THRESHOLD_SECONDS) {
            return [self::STATUS_GREEN, 'recent_heartbeat'];
        }

        if ($heartbeatAge < self::YELLOW_THRESHOLD_SECONDS) {
            return [self::STATUS_YELLOW, 'heartbeat_slow'];
        }

        if ($queueDepth >= self::RED_QUEUE_DEPTH_THRESHOLD) {
            return [self::STATUS_RED, 'heartbeat_stale_with_backlog'];
        }

        return [self::STATUS_RED, 'heartbeat_stale'];
    }

    private function readHeartbeat(): ?int
    {
        $path = $this->heartbeatPath();
        if (!is_file($path)) {
            return null;
        }
        $ts = @filemtime($path);
        return is_int($ts) ? $ts : null;
    }

    private function heartbeatPath(): string
    {
        return $this->kernel->getProjectDir() . '/var/jobs/' . self::HEARTBEAT_FILE;
    }

    private function queueDepth(): int
    {
        try {
            $sql = "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async'";
            $value = $this->connection->fetchOne($sql);
            return is_numeric($value) ? (int) $value : 0;
        } catch (\Throwable) {
            // Table may not exist yet (fresh install before messenger:setup).
            return 0;
        }
    }

    private function failedJobs24h(): int
    {
        try {
            $sql = "SELECT COUNT(*) FROM messenger_messages
                    WHERE queue_name = 'failed'
                      AND created_at >= :cutoff";
            $cutoff = (new \DateTimeImmutable('-' . self::FAILED_LOOKBACK_SECONDS . ' seconds'))
                ->format('Y-m-d H:i:s');
            $value = $this->connection->fetchOne($sql, ['cutoff' => $cutoff]);
            return is_numeric($value) ? (int) $value : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    private function pendingJobCount(): int
    {
        $dir = $this->kernel->getProjectDir() . '/var/jobs';
        if (!is_dir($dir)) {
            return 0;
        }
        $count = 0;
        $files = glob($dir . '/*.json') ?: [];
        foreach ($files as $file) {
            $raw = @file_get_contents($file);
            if ($raw === false) {
                continue;
            }
            $data = json_decode($raw, true);
            if (is_array($data) && ($data['status'] ?? null) === 'pending') {
                ++$count;
            }
        }
        return $count;
    }
}
