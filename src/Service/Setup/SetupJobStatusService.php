<?php

declare(strict_types=1);

namespace App\Service\Setup;

use Symfony\Component\HttpKernel\KernelInterface;

/**
 * File-based status store for setup-wizard async jobs.
 *
 * Replaces session-based status because Symfony's Session::save() is a no-op
 * after the first session_write_close() (triggered by the first save() before
 * fastcgi_finish_request). The terminal status (success/failed) written by
 * the background worker therefore never reaches storage and the poller sees
 * 'running' forever.
 *
 * File-based status sidesteps the session-lifecycle issue entirely and adds
 * a heartbeat timestamp so dead workers (killed by request_terminate_timeout
 * or OOM) can be detected as 'failed' instead of staying 'running' forever.
 */
final class SetupJobStatusService
{
    private const STALE_AFTER_SECONDS = 300; // 5 min without update → assume worker died

    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    public function start(string $job): void
    {
        $this->write($job, [
            'status' => 'running',
            'message' => null,
            'started_at' => time(),
            'updated_at' => time(),
        ]);
    }

    public function succeed(string $job, ?string $message = null, array $payload = []): void
    {
        $this->write($job, [
            'status' => 'success',
            'message' => $message,
            'payload' => $payload,
            'started_at' => $this->read($job)['started_at'] ?? time(),
            'updated_at' => time(),
        ]);
    }

    public function fail(string $job, string $message, array $payload = []): void
    {
        $this->write($job, [
            'status' => 'failed',
            'message' => $message,
            'payload' => $payload,
            'started_at' => $this->read($job)['started_at'] ?? time(),
            'updated_at' => time(),
        ]);
    }

    public function heartbeat(string $job): void
    {
        $current = $this->read($job);
        if (($current['status'] ?? null) !== 'running') {
            return;
        }
        $current['updated_at'] = time();
        $this->write($job, $current);
    }

    /**
     * @return array{status: string, message: ?string, started_at?: int, updated_at?: int}
     */
    public function read(string $job): array
    {
        $path = $this->path($job);
        if (!is_file($path)) {
            return ['status' => 'idle', 'message' => null];
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return ['status' => 'idle', 'message' => null];
        }
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['status'])) {
            return ['status' => 'idle', 'message' => null];
        }

        // Detect stale 'running' (worker died without writing terminal state)
        if ($data['status'] === 'running' && isset($data['updated_at'])) {
            $age = time() - (int) $data['updated_at'];
            if ($age > self::STALE_AFTER_SECONDS) {
                return [
                    'status' => 'failed',
                    'message' => sprintf(
                        'Worker reagiert seit %ds nicht mehr (vermutlich abgebrochen). Bitte erneut versuchen.',
                        $age
                    ),
                    'started_at' => $data['started_at'] ?? null,
                    'updated_at' => $data['updated_at'],
                ];
            }
        }

        return $data;
    }

    public function clear(string $job): void
    {
        $path = $this->path($job);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    private function write(string $job, array $data): void
    {
        $path = $this->path($job);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        // Atomic write via tmp + rename to avoid poller reading partial JSON
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE));
        @rename($tmp, $path);
    }

    private function path(string $job): string
    {
        if (preg_match('/^[a-z0-9_]+$/', $job) !== 1) {
            throw new \InvalidArgumentException('Invalid job key: ' . $job);
        }
        return $this->kernel->getProjectDir() . '/var/cache/setup_jobs/' . $job . '.json';
    }
}
