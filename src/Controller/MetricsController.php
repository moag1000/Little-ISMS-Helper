<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Prometheus text-exposition metrics endpoint — process/app health only.
 *
 * Exposition format: text/plain; version=0.0.4 (Prometheus text format 0.0.4).
 * No external Prometheus library — we hand-roll the handful of lines needed.
 *
 * Tenant-disclosure safety
 * ─────────────────────────
 * This endpoint is intentionally restricted to PROCESS and BUILD metrics only.
 * The following are explicitly NOT exposed:
 *
 *   • Per-tenant entity counts  (risks, assets, controls, incidents, …)
 *   • Total entity counts across tenants
 *   • User counts (total, active, per-tenant)
 *   • Tenant names, IDs, or any tenant-identifying data
 *   • Queue depth (msg count in messenger_messages — reveals message volume)
 *   • Any data that could disclose customer scale or usage patterns
 *
 * Only safe infra/process gauges are included:
 *
 *   app_up                       — always 1 (liveness signal for alerting rules)
 *   app_build_info               — build metadata (version label only)
 *   process_resident_memory_bytes — RSS via memory_get_usage(true); PHP process cost
 *   php_memory_limit_bytes       — configured memory_limit for capacity planning
 *
 * Route is intentionally locale-INdependent (no /{_locale} prefix).
 */
final class MetricsController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/metrics', name: 'app_metrics', methods: ['GET'])]
    public function __invoke(): Response
    {
        $version = $this->resolveVersion();
        $memoryBytes = memory_get_usage(true);
        $memoryLimit = $this->parseMemoryLimit(ini_get('memory_limit') ?: '-1');

        $lines = [];

        // ── app_up ──────────────────────────────────────────────────────────
        // Simple 1/0 gauge. If this endpoint responds, the app is up.
        // Alertmanager: `alert: AppDown` when `app_up == 0`.
        $lines[] = '# HELP app_up Application is up and serving requests.';
        $lines[] = '# TYPE app_up gauge';
        $lines[] = 'app_up 1';

        // ── app_build_info ───────────────────────────────────────────────────
        // Carries the version label so dashboards can annotate deploys.
        // No sensitive information — version string only (e.g. "3.10.0").
        $safeVersion = preg_replace('/[^a-zA-Z0-9.\-_+]/', '', $version) ?? 'dev';
        $lines[] = '# HELP app_build_info Build metadata of the running instance.';
        $lines[] = '# TYPE app_build_info gauge';
        $lines[] = sprintf('app_build_info{version="%s"} 1', $safeVersion);

        // ── process_resident_memory_bytes ────────────────────────────────────
        // PHP memory_get_usage(true) returns the real allocation from the OS
        // (aligned to page size). Useful for alerting on memory leaks.
        $lines[] = '# HELP process_resident_memory_bytes PHP process memory allocation (bytes, OS-aligned).';
        $lines[] = '# TYPE process_resident_memory_bytes gauge';
        $lines[] = sprintf('process_resident_memory_bytes %d', $memoryBytes);

        // ── php_memory_limit_bytes ───────────────────────────────────────────
        // The configured PHP memory_limit. -1 = unlimited. Useful for
        // computing "memory used / limit" ratio in Grafana.
        $lines[] = '# HELP php_memory_limit_bytes PHP memory_limit in bytes (-1 = unlimited).';
        $lines[] = '# TYPE php_memory_limit_bytes gauge';
        $lines[] = sprintf('php_memory_limit_bytes %d', $memoryLimit);

        $body = implode("\n", $lines) . "\n";

        return new Response(
            $body,
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8'],
        );
    }

    /**
     * Reads the application version from composer.json (single source of truth),
     * matching AppVersionExtension behaviour. Falls back to 'dev'.
     */
    private function resolveVersion(): string
    {
        $path = $this->projectDir . '/composer.json';
        if (!is_readable($path)) {
            return 'dev';
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? (string) ($data['version'] ?? 'dev') : 'dev';
    }

    /**
     * Converts a PHP memory_limit string (e.g. "256M", "1G") to bytes.
     * Returns -1 for unlimited ("-1").
     */
    private function parseMemoryLimit(string $limit): int
    {
        if ($limit === '-1') {
            return -1;
        }

        $limit = trim($limit);
        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
