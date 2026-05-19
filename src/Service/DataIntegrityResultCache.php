<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Lightweight cache for the most recent async integrity-check summary.
 *
 * Stores ONLY scalar counts + a completed-at timestamp at
 * var/data_integrity/last.json — the GET /admin/data-repair/ page reads
 * this to show "Last full integrity check ran X ago — N orphans, M
 * duplicates" without re-running the (slow) scan on every page hit.
 *
 * Detailed entity lists for repair actions are still produced inline by
 * {@see DataIntegrityService} on each render (they depend on live data
 * for correctness — a row deleted between renders would otherwise leave a
 * stale repair form). The cache is purely a "we know how many issues
 * existed N minutes ago" hint surfaced as a banner on the index page.
 *
 * Atomic write (tmp + rename) keeps the file safe to read concurrently.
 *
 * Used by Phase-2.5 async-jobs rollout — see RunFullIntegrityCheckJob.
 */
final class DataIntegrityResultCache
{
    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    /**
     * Persist a check-summary snapshot.
     *
     * @param array<string, int|string> $counts Scalar count values produced by the
     *                                          job (e.g. orphans_total, duplicates_groups,
     *                                          broken_references, etc.)
     * @param int                       $durationMs Wall-clock duration of the scan
     */
    public function write(array $counts, int $durationMs): void
    {
        $payload = [
            'completed_at' => time(),
            'duration_ms' => $durationMs,
            'counts' => $counts,
        ];
        $this->writePayload($payload);
    }

    /**
     * Read the persisted last-result, or null if no scan has ever completed.
     *
     * @return array{completed_at: int, duration_ms: int, counts: array<string, int|string>}|null
     */
    public function read(): ?array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        try {
            $data = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data) || !isset($data['completed_at'])) {
            return null;
        }
        return $data;
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    private function writePayload(array $payload): void
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $encoded = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            // @intentional-assertion: payload should always be JSON-encodable
            throw new \RuntimeException('Failed to serialise integrity-check summary.');
        }
        file_put_contents($tmp, $encoded);
        @rename($tmp, $path);
    }

    private function path(): string
    {
        return $this->kernel->getProjectDir() . '/var/data_integrity/last.json';
    }
}
