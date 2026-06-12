<?php

declare(strict_types=1);

namespace App\Tests\Service\Bsi;

use App\Entity\ComplianceMapping;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Bsi\IsoToBsiGapService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * P3 Tier-A quality verification: GDPR ↔ ISO 27701:2025 Annex D corroboration.
 *
 * Design decisions:
 *   amtlich_source : ISO 27701:2025 Annex D (normative), no panel needed.
 *   provenanceSource: 'official_iso27701_gdpr_annex' — maps to amtlich trust tier via
 *                     IsoToBsiGapService::trustOf().
 *   Corroboration   : MappingCorroborationService::corroborate(ISO27701_2025, GDPR,
 *                     officialProvenance='official_iso27701_gdpr_annex') finds 27
 *                     already_official rows and 0 residual (the official Annex D file
 *                     IS the entire trusted corpus for this pair).
 *   No panel needed : ISO 27701:2025 Annex D is authoritative + comprehensive.
 *
 * Assertions:
 *   1. Official Annex D fixture exists and has exactly 27 mapping pairs.
 *   2. Every pair carries provenanceSource='official_iso27701_gdpr_annex'.
 *   3. IsoToBsiGapService::trustOf() resolves those pairs to TIER_AMTLICH.
 *   4. No ISO requirement prose appears in the shipped fixtures
 *      (license leak-guard — only control IDs and GDPR article IDs are permitted).
 *   5. Corroborated pairs (provenanceSource='crt_corroborated') are trusted
 *      (amtlich_gestuetzt tier, not in prüfen bucket).
 *   6. trustOf() returns TIER_AMTLICH for the new official sentinel.
 */
final class Gdpr27701QualityTest extends TestCase
{
    private const OFFICIAL_CRT_FILE = __DIR__ . '/../../../fixtures/library/mappings/iso27701-2025_to_gdpr_official-crt_v1.yaml';
    private const HEURISTIC_FILE    = __DIR__ . '/../../../fixtures/library/mappings/iso27701-2025_to_gdpr_v1.0.yaml';

    /** Expected count of amtlich Annex D pairs per ISO 27701:2025 Annex D. */
    private const EXPECTED_AMTLICH_PAIRS = 27;

    /** provenanceSource sentinel used for official Annex D rows. */
    private const OFFICIAL_PROVENANCE = 'official_iso27701_gdpr_annex';

    /** @var array<mixed> */
    private static array $officialData = [];

    private IsoToBsiGapService $trustService;

    public static function setUpBeforeClass(): void
    {
        self::$officialData = Yaml::parseFile(self::OFFICIAL_CRT_FILE);
    }

    protected function setUp(): void
    {
        $this->trustService = new IsoToBsiGapService(
            $this->createMock(ComplianceRequirementRepository::class),
            $this->createMock(ComplianceMappingRepository::class),
            $this->createMock(ComplianceRequirementFulfillmentRepository::class),
        );
    }

    // ── Fixture existence + structure ──────────────────────────────────────────

    #[Test]
    public function official_annex_d_fixture_exists(): void
    {
        self::assertFileExists(
            self::OFFICIAL_CRT_FILE,
            'ISO 27701:2025 Annex D official fixture must exist at fixtures/library/mappings/iso27701-2025_to_gdpr_official-crt_v1.yaml',
        );
    }

    #[Test]
    public function official_fixture_has_required_top_level_keys(): void
    {
        self::assertArrayHasKey('schema_version', self::$officialData);
        self::assertArrayHasKey('library', self::$officialData);
        self::assertArrayHasKey('mappings', self::$officialData);
    }

    #[Test]
    public function official_fixture_source_framework_is_iso27701_2025(): void
    {
        $actual = self::$officialData['library']['source_framework'] ?? '';
        self::assertSame(
            'ISO27701_2025',
            $actual,
            sprintf(
                'source_framework must be "ISO27701_2025" (DB code). Got "%s". '
                . 'A mismatch causes MappingLibraryLoader to silently skip all pairs at import time.',
                $actual,
            ),
        );
    }

    #[Test]
    public function official_fixture_target_framework_is_gdpr(): void
    {
        $actual = self::$officialData['library']['target_framework'] ?? '';
        self::assertSame(
            'GDPR',
            $actual,
            sprintf(
                'target_framework must be "GDPR" (DB code). Got "%s". '
                . 'A mismatch causes MappingLibraryLoader to silently skip all pairs at import time.',
                $actual,
            ),
        );
    }

    // ── Amtlich pair count ─────────────────────────────────────────────────────

    /**
     * ISO 27701:2025 Annex D lists exactly 27 GDPR-article correspondence rows
     * that have matching DB requirements in the ISO27701_2025 framework.
     * This assertion is the immutable contract for the P3 quality layer.
     */
    #[Test]
    public function official_fixture_contains_exactly_27_amtlich_pairs(): void
    {
        $count = count(self::$officialData['mappings'] ?? []);
        self::assertSame(
            self::EXPECTED_AMTLICH_PAIRS,
            $count,
            sprintf(
                'Official ISO 27701:2025 Annex D fixture must contain exactly %d mapping pairs '
                . '(authoritative Annex D count). Got %d. '
                . 'Do not add or remove pairs without updating the source reference.',
                self::EXPECTED_AMTLICH_PAIRS,
                $count,
            ),
        );
    }

    // ── ProvenanceSource on every official pair ────────────────────────────────

    /**
     * Every official pair must carry provenanceSource='official_iso27701_gdpr_annex'.
     * MappingCorroborationService uses this sentinel to identify official rows
     * (they are never touched during corroboration).
     */
    #[Test]
    public function every_official_pair_carries_correct_provenance_source(): void
    {
        $missing = [];

        foreach (self::$officialData['mappings'] ?? [] as $i => $entry) {
            $ps = $entry['provenanceSource'] ?? $entry['provenance_source'] ?? null;
            if ($ps !== self::OFFICIAL_PROVENANCE) {
                $missing[] = sprintf(
                    'entry[%d] source=%s: provenanceSource="%s" (expected "%s")',
                    $i,
                    $entry['source'] ?? '?',
                    (string) $ps,
                    self::OFFICIAL_PROVENANCE,
                );
            }
        }

        self::assertEmpty(
            $missing,
            sprintf(
                'All official Annex D pairs must carry provenanceSource="%s". Violations: %s',
                self::OFFICIAL_PROVENANCE,
                implode('; ', $missing),
            ),
        );
    }

    // ── Trust-tier: amtlich for official_iso27701_gdpr_annex ──────────────────

    /**
     * IsoToBsiGapService::trustOf() must return TIER_AMTLICH for the new provenance sentinel.
     * This is the key extension in P3 Tier-A: official GDPR Annex D pairs are amtlich,
     * not heuristisch (the previous fallback default).
     */
    #[Test]
    public function trustOf_returns_amtlich_for_official_iso27701_gdpr_annex(): void
    {
        $m = new ComplianceMapping();
        $m->setProvenanceSource(IsoToBsiGapService::PROVENANCE_OFFICIAL_ISO27701_GDPR);

        self::assertSame(
            IsoToBsiGapService::TIER_AMTLICH,
            $this->trustService->trustOf($m),
            'PROVENANCE_OFFICIAL_ISO27701_GDPR must map to TIER_AMTLICH — '
            . 'official ISO 27701:2025 Annex D is amtlich source material.',
        );
    }

    #[Test]
    public function official_iso27701_gdpr_provenance_constant_matches_fixture_value(): void
    {
        self::assertSame(
            self::OFFICIAL_PROVENANCE,
            IsoToBsiGapService::PROVENANCE_OFFICIAL_ISO27701_GDPR,
            'IsoToBsiGapService::PROVENANCE_OFFICIAL_ISO27701_GDPR must equal '
            . '"official_iso27701_gdpr_annex" — the value used in the fixture and the DB.',
        );
    }

    #[Test]
    public function official_iso27701_gdpr_provenance_is_in_official_sources_list(): void
    {
        self::assertContains(
            IsoToBsiGapService::PROVENANCE_OFFICIAL_ISO27701_GDPR,
            IsoToBsiGapService::PROVENANCE_OFFICIAL_SOURCES,
            'PROVENANCE_OFFICIAL_ISO27701_GDPR must be in PROVENANCE_OFFICIAL_SOURCES — '
            . 'the list of all amtlich provenance sentinels.',
        );
    }

    #[Test]
    public function official_bsi_crt_is_still_in_official_sources_list(): void
    {
        self::assertContains(
            IsoToBsiGapService::PROVENANCE_OFFICIAL_CRT,
            IsoToBsiGapService::PROVENANCE_OFFICIAL_SOURCES,
            'PROVENANCE_OFFICIAL_CRT (official_bsi_crosswalk) must remain in PROVENANCE_OFFICIAL_SOURCES — '
            . 'regression guard for the BSI Grundschutz use-case.',
        );
    }

    // ── Trust-tier: amtlich_gestuetzt for corroborated pairs ──────────────────

    /**
     * Pairs elevated by MappingCorroborationService get provenanceSource='crt_corroborated'.
     * They must be in the amtlich_gestuetzt tier and must NOT require review.
     */
    #[Test]
    public function corroborated_pairs_are_in_amtlich_gestuetzt_tier(): void
    {
        $m = new ComplianceMapping();
        $m->setProvenanceSource(IsoToBsiGapService::PROVENANCE_CRT_CORROBORATED);

        self::assertSame(
            IsoToBsiGapService::TIER_AMTLICH_GESTUETZT,
            $this->trustService->trustOf($m),
            'crt_corroborated provenanceSource must map to TIER_AMTLICH_GESTUETZT.',
        );
    }

    #[Test]
    public function corroborated_pairs_do_not_require_review(): void
    {
        $m = new ComplianceMapping();
        $m->setProvenanceSource(IsoToBsiGapService::PROVENANCE_CRT_CORROBORATED);

        self::assertFalse(
            $this->trustService->requiresReview($m),
            'amtlich_gestuetzt corroborated pairs must NOT land in the prüfen bucket.',
        );
    }

    // ── License leak-guard: no ISO prose in shipped fixtures ──────────────────

    /**
     * ISO 27701:2025 requirement prose is copyright ISO/IEC.
     * Only control IDs (e.g. '27701:2025-A.7.2.1') and GDPR article IDs
     * (e.g. 'GDPR-5.1.b') may appear as structured data in the fixture.
     *
     * This test guards against accidentally embedding copyrighted requirement text.
     * Allowed in YAML files: 'rationale', 'audit_evidence_hint' written by maintainers
     * are fine (they are paraphrases, not verbatim copies).
     *
     * Prohibited: verbatim ISO clause titles or requirement text inside the 'source'
     * or 'target' ID fields (those must remain IDs only).
     *
     * We test a structural property: no 'source' ID in the official CRT file
     * contains a space (IDs are compact codes; prose would contain spaces).
     */
    #[Test]
    public function official_fixture_source_ids_contain_no_spaces(): void
    {
        $invalid = [];

        foreach (self::$officialData['mappings'] ?? [] as $entry) {
            $src = (string) ($entry['source'] ?? '');
            if (str_contains($src, ' ')) {
                $invalid[] = $src;
            }
        }

        self::assertEmpty(
            $invalid,
            'Official fixture source IDs must be compact codes (no spaces). '
            . 'Prose text in source IDs indicates verbatim ISO clause text (license violation). '
            . 'Violating IDs: ' . implode(', ', $invalid),
        );
    }

    #[Test]
    public function official_fixture_target_ids_contain_no_spaces(): void
    {
        $invalid = [];

        foreach (self::$officialData['mappings'] ?? [] as $entry) {
            $tgt = (string) ($entry['target'] ?? '');
            if (str_contains($tgt, ' ')) {
                $invalid[] = $tgt;
            }
        }

        self::assertEmpty(
            $invalid,
            'Official fixture target IDs must be compact codes (no spaces). '
            . 'Prose text in target IDs indicates verbatim ISO clause text (license violation). '
            . 'Violating IDs: ' . implode(', ', $invalid),
        );
    }

    /**
     * The library-level provenance block must declare the integrity_note
     * confirming that no ISO prose has been reproduced.
     */
    #[Test]
    public function official_fixture_has_integrity_note_in_provenance(): void
    {
        $integrityNote = self::$officialData['library']['provenance']['integrity_note'] ?? null;

        self::assertNotNull(
            $integrityNote,
            'Official Annex D fixture must contain a provenance.integrity_note confirming '
            . 'that only control IDs are reproduced (no ISO prose).',
        );

        self::assertNotEmpty(
            (string) $integrityNote,
            'integrity_note must not be empty.',
        );
    }

    // ── Source ID format guard ─────────────────────────────────────────────────

    #[Test]
    public function all_official_source_ids_have_27701_2025_prefix(): void
    {
        $invalid = [];

        foreach (self::$officialData['mappings'] ?? [] as $entry) {
            $src = (string) ($entry['source'] ?? '');
            if (!str_starts_with($src, '27701:2025-') && !preg_match('/^27701:2025-\d/', $src)) {
                // Accept management clause IDs like '27701:2025-5.x', '27701:2025-6.x.x', '27701:2025-8.x'
                if (!preg_match('/^27701:2025-[5-9]/', $src)) {
                    $invalid[] = $src;
                }
            }
        }

        self::assertEmpty(
            $invalid,
            'All source IDs in the official Annex D fixture must start with "27701:2025-". '
            . 'Invalid IDs: ' . implode(', ', $invalid),
        );
    }

    #[Test]
    public function all_official_target_ids_have_gdpr_prefix(): void
    {
        $invalid = [];

        foreach (self::$officialData['mappings'] ?? [] as $entry) {
            $tgt = (string) ($entry['target'] ?? '');
            if (!str_starts_with($tgt, 'GDPR-')) {
                $invalid[] = $tgt;
            }
        }

        self::assertEmpty(
            $invalid,
            'All target IDs in the official Annex D fixture must start with "GDPR-". '
            . 'Invalid IDs: ' . implode(', ', $invalid),
        );
    }

    // ── Library-level provenance on official fixture ───────────────────────────

    #[Test]
    public function official_fixture_library_carries_official_provenance_source(): void
    {
        $provenance = self::$officialData['library']['provenance'] ?? [];
        // YAML uses snake_case key name
        $actual     = (string) ($provenance['provenance_source'] ?? $provenance['provenanceSource'] ?? '');

        self::assertSame(
            self::OFFICIAL_PROVENANCE,
            $actual,
            sprintf(
                'library.provenance.provenance_source must be "%s" in the official Annex D fixture. '
                . 'Got "%s". This marker distinguishes amtliche from derived mappings in audit tools.',
                self::OFFICIAL_PROVENANCE,
                $actual,
            ),
        );
    }

    #[Test]
    public function official_fixture_methodology_type_is_published_official_mapping(): void
    {
        $type = self::$officialData['library']['methodology']['type'] ?? null;
        self::assertSame(
            'published_official_mapping',
            $type,
            'methodology.type must be "published_official_mapping" for the Annex D source. '
            . 'This ensures audit tooling can distinguish authoritative from derived mappings.',
        );
    }
}
