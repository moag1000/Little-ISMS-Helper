<?php

declare(strict_types=1);

namespace App\Service\Job;

use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Generic file-based status store for async admin jobs.
 *
 * Stores job status JSON files in var/jobs/<id>.json.
 * Uses atomic write (tmp + rename) to avoid poller reading partial JSON.
 * Detects stale 'running' jobs (worker died without writing terminal state).
 *
 * @see \App\Service\Setup\SetupJobStatusService — setup-wizard-specific
 *   wrapper that delegates here (BC alias).
 */
final class JobStatusService
{
    /** 5 minutes without heartbeat → assume worker died */
    private const STALE_AFTER_SECONDS = 300;

    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    /**
     * Create and persist a new job, returning its UUID.
     */
    public function create(string $name, array $payload = []): string
    {
        // UUID v4 — no external package needed
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0F) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3F) | 0x80);
        $h = bin2hex($b);
        $id = sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20));
        $this->write($id, [
            'id' => $id,
            'name' => $name,
            'status' => 'pending',
            'message' => null,
            'progress_current' => 0,
            'progress_total' => 0,
            'started_at' => null,
            'updated_at' => time(),
            'payload' => $payload,
            'error_trace' => null,
        ]);
        return $id;
    }

    public function markRunning(string $id): void
    {
        $data = $this->readRaw($id);
        $data['status'] = 'running';
        $data['started_at'] = $data['started_at'] ?? time();
        $data['updated_at'] = time();
        $this->write($id, $data);
    }

    public function markSucceeded(string $id, ?string $message = null): void
    {
        $data = $this->readRaw($id);
        $data['status'] = 'succeeded';
        $data['message'] = $message;
        $data['updated_at'] = time();
        $this->write($id, $data);
    }

    public function markFailed(string $id, string $message, ?string $errorTrace = null): void
    {
        $data = $this->readRaw($id);
        $data['status'] = 'failed';
        $data['message'] = $message;
        $data['error_trace'] = $errorTrace;
        $data['updated_at'] = time();
        $this->write($id, $data);
    }

    public function heartbeat(string $id): void
    {
        $data = $this->readRaw($id);
        if (($data['status'] ?? null) !== 'running') {
            return;
        }
        $data['updated_at'] = time();
        $this->write($id, $data);
    }

    public function updateProgress(string $id, int $current, int $total, ?string $message = null): void
    {
        $data = $this->readRaw($id);
        $data['progress_current'] = $current;
        $data['progress_total'] = $total;
        if ($message !== null) {
            $data['message'] = $message;
        }
        $data['updated_at'] = time();
        $this->write($id, $data);
    }

    /**
     * Read job status, applying stale-detection for stuck 'running' jobs.
     *
     * @return array{id: string, name: string, status: string, message: ?string,
     *               progress_current: int, progress_total: int,
     *               started_at: ?int, updated_at: ?int,
     *               payload: array<string,mixed>, error_trace: ?string}
     */
    public function read(string $id): array
    {
        $data = $this->readRaw($id);

        if ($data['status'] === 'running' && isset($data['updated_at'])) {
            $age = time() - (int) $data['updated_at'];
            if ($age > self::STALE_AFTER_SECONDS) {
                $data['status'] = 'failed';
                $data['message'] = sprintf(
                    'Worker has not responded for %ds (likely crashed). Please retry.',
                    $age,
                );
            }
        }

        return $data;
    }

    public function exists(string $id): bool
    {
        return is_file($this->path($id));
    }

    public function delete(string $id): void
    {
        $path = $this->path($id);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────

    private function readRaw(string $id): array
    {
        $path = $this->path($id);
        if (!is_file($path)) {
            return $this->emptyRecord($id);
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return $this->emptyRecord($id);
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return $this->emptyRecord($id);
        }
        return $data + $this->emptyRecord($id);
    }

    private function emptyRecord(string $id): array
    {
        return [
            'id' => $id,
            'name' => '',
            'status' => 'unknown',
            'message' => null,
            'progress_current' => 0,
            'progress_total' => 0,
            'started_at' => null,
            'updated_at' => null,
            'payload' => [],
            'error_trace' => null,
        ];
    }

    private function write(string $id, array $data): void
    {
        $this->validateId($id);
        $path = $this->path($id);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        file_put_contents($tmp, json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES));
        @rename($tmp, $path);
    }

    private function path(string $id): string
    {
        $this->validateId($id);
        return $this->kernel->getProjectDir() . '/var/jobs/' . $id . '.json';
    }

    private function validateId(string $id): void
    {
        // Accept UUID v4 (rfc4122) format
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id)) {
            throw new \InvalidArgumentException('Invalid job ID (must be UUID v4): ' . $id);
        }
    }
}
