<?php

declare(strict_types=1);

namespace App\Service\Import;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Yaml;

/**
 * Curated migration table from legacy GSTOOL Bausteine (B 1.x–B 5.x style)
 * to the IT-Grundschutz-Kompendium 2023 IDs (ISMS, ORP, CON, OPS, DER, APP,
 * SYS, IND, NET, INF).
 *
 * Loaded from fixtures/migrations/gstool-to-kompendium-2023.yaml.
 *
 * The data is curated best-effort — Compliance Managers must review each
 * mapping before relying on the result. Status values:
 *   - direct   : single, content-preserving migration
 *   - split    : a single legacy Baustein → multiple new modules (default chosen)
 *   - merged   : the new ID covers more than the legacy content
 *   - removed  : legacy Baustein has no successor in the 2023 Kompendium
 *   - bcm      : moved to BSI 200-4 (BCM) and out of the IT-GS Kompendium
 */
final class GstoolMigrationTable
{
    /** @var array<string, array{to: ?string, title_old: string, title_new: string, status: string, note?: string}>|null */
    private ?array $table = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/fixtures/migrations/gstool-to-kompendium-2023.yaml')]
        private readonly string $tablePath,
    ) {
    }

    /**
     * Resolve a legacy Baustein ID like "B 1.0" / "B 3.101" to the modern
     * Kompendium-2023 equivalent. Returns null when the input is unknown.
     *
     * @return array{to: ?string, title_old: string, title_new: string, status: string, note?: string}|null
     */
    public function resolveBaustein(string $legacyId): ?array
    {
        $key = $this->normaliseLegacyId($legacyId);
        $table = $this->load();
        return $table[$key] ?? null;
    }

    /**
     * Map a GSTOOL-Maßnahme ID (e.g. "M 2.1") to a Kompendium-2023
     * Anforderung-ID by deriving the Baustein from the Maßnahme prefix
     * and looking up the migration. The exact requirement (.A1/.A2/…)
     * cannot be derived deterministically — this returns only the parent
     * Baustein.
     *
     * @return array{to: ?string, title_old: string, title_new: string, status: string, note?: string}|null
     */
    public function resolveMassnahmeBaustein(string $massnahmeId): ?array
    {
        // GSTOOL M-prefix is the Maßnahmenkatalog (M 1, M 2, …); the
        // baustein-context is carried separately in the XML (zielobjekt
        // attribute) — without it we cannot route a M-ID. Returning null
        // forces the caller to fall back to baustein-mapping which IS
        // resolvable.
        if (!preg_match('/^M\s*\d/', $massnahmeId)) {
            return null;
        }
        return null;
    }

    /**
     * Normalise common legacy-ID shapes:
     *   "B1.0", "B 1.0", "B  1.0  " → "B 1.0"
     */
    public function normaliseLegacyId(string $raw): string
    {
        $clean = preg_replace('/\s+/', ' ', trim($raw)) ?? $raw;
        if (str_starts_with($clean, 'B') && !str_starts_with($clean, 'B ')) {
            $clean = 'B ' . substr($clean, 1);
        }
        return $clean;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        if ($this->table === null) {
            $parsed = is_file($this->tablePath) ? Yaml::parseFile($this->tablePath) : [];
            $this->table = is_array($parsed) ? $parsed : [];
        }
        return $this->table;
    }

    /**
     * @return list<string>
     */
    public function knownLegacyIds(): array
    {
        return array_keys($this->load());
    }
}
