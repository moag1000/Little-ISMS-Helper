<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Per-section cache for the data-repair sub-pages.
 *
 * Mirrors {@see DataIntegrityResultCache} but keyed by a section slug
 * (`orphans`, `duplicates`, `broken_references`, `health`) so each
 * sub-page can show "Last refresh: X ago — N items" without re-running
 * its slow scan on every GET.
 *
 * Cache file layout: `var/data_integrity/<section>.json` written
 * atomically via tmp + rename so concurrent reads stay safe.
 *
 * The payload deliberately mixes scalar counts with a small detail
 * preview (typically first 100 items) so the sub-page can render
 * meaningful UI from the cache alone — the live repair routes still
 * read fresh data when they actually perform the mutation, which is
 * the only place stale data could cause a wrong decision.
 */
final class SectionScanResultCache
{
    public const SECTION_ORPHANS = 'orphans';
    public const SECTION_DUPLICATES = 'duplicates';
    public const SECTION_BROKEN_REFERENCES = 'broken_references';
    public const SECTION_HEALTH = 'health';

    private const SECTIONS = [
        self::SECTION_ORPHANS,
        self::SECTION_DUPLICATES,
        self::SECTION_BROKEN_REFERENCES,
        self::SECTION_HEALTH,
    ];

    public function __construct(private readonly KernelInterface $kernel)
    {
    }

    /**
     * Persist a section-scan snapshot.
     *
     * @param array<string, mixed> $payload Section-specific scalar counts +
     *                                      optional preview rows. Job authors
     *                                      define their own shape — the read
     *                                      side handles missing keys gracefully.
     * @param int                  $durationMs Wall-clock duration of the scan
     */
    public function write(string $section, array $payload, int $durationMs): void
    {
        $this->assertKnownSection($section);

        $wrapper = [
            'section' => $section,
            'completed_at' => time(),
            'duration_ms' => $durationMs,
            'payload' => $payload,
        ];
        $this->writePayload($section, $wrapper);
    }

    /**
     * Read the persisted last-result for a section, or null if no scan
     * has ever completed for that section.
     *
     * @return array{section: string, completed_at: int, duration_ms: int, payload: array<string, mixed>}|null
     */
    public function read(string $section): ?array
    {
        $this->assertKnownSection($section);

        $path = $this->path($section);
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
        if (!is_array($data) || !isset($data['completed_at']) || !isset($data['payload'])) {
            return null;
        }
        return $data;
    }

    /**
     * Lightweight existence check — used by the index page to decide whether
     * to show "Never scanned" vs a real timestamp without paying for a
     * full JSON decode.
     */
    public function exists(string $section): bool
    {
        $this->assertKnownSection($section);
        return is_file($this->path($section));
    }

    /**
     * Convenience: return all section snapshots keyed by section name. Used
     * by the index page to render KPI tiles for every section in one pass.
     *
     * @return array<string, array{section: string, completed_at: int, duration_ms: int, payload: array<string, mixed>}|null>
     */
    public function readAll(): array
    {
        $result = [];
        foreach (self::SECTIONS as $section) {
            $result[$section] = $this->read($section);
        }
        return $result;
    }

    private function assertKnownSection(string $section): void
    {
        if (!in_array($section, self::SECTIONS, true)) {
            // @intentional-assertion: programmer error — invalid section slug
            throw new \InvalidArgumentException(sprintf(
                'Unknown section "%s" (expected one of: %s).',
                $section,
                implode(', ', self::SECTIONS),
            ));
        }
    }

    private function writePayload(string $section, array $payload): void
    {
        $path = $this->path($section);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $encoded = json_encode($payload, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            // @intentional-assertion: payload should always be JSON-encodable
            throw new \RuntimeException(sprintf(
                'Failed to serialise section-scan summary for "%s".',
                $section,
            ));
        }
        file_put_contents($tmp, $encoded);
        @rename($tmp, $path);
    }

    private function path(string $section): string
    {
        return $this->kernel->getProjectDir() . '/var/data_integrity/' . $section . '.json';
    }
}
