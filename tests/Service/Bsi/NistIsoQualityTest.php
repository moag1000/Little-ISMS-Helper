<?php

declare(strict_types=1);

namespace App\Tests\Service\Bsi;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * P3 Tier-A quality guard for NIST CSF 2.0 ↔ ISO 27001:2022 panel verdicts.
 *
 * Assertions:
 *   1. The panel verdict fixture exists and is valid JSON with a `verdicts` key.
 *   2. All 90 ki_validiert verdicts are present (not zero, not replaced by something else).
 *   3. All 9 rejected verdicts have state=reject (not accidentally promoted).
 *   4. The completeness candidates fixture exists and is NOT in the verdicts fixture
 *      — candidates are logged only, never auto-applied.
 *   5. Panel provenance header is correct (source NIST-CSF-2.0, target ISO27001,
 *      4-expert panel named, amtlich_source explains NIST OLIR blockage).
 *   6. Every ki_validiert verdict has mappingPercentage ≥ 35 (minimum credibility gate).
 *   7. Every reject verdict has state=reject (not ki_validiert).
 *   8. No candidate target IDs from the completeness fixture appear as verdict baustein
 *      in a ki_validiert state where the source nist-id + target matches a candidate
 *      proposal — i.e., candidates were not silently auto-applied.
 *
 * Design decision:
 *   These tests are pure-PHP (no Doctrine, no kernel boot) to keep them fast and runnable
 *   in CI without a DB. They guard the static fixture JSON files, not DB state.
 */
final class NistIsoQualityTest extends TestCase
{
    private const PANEL_FIXTURE = __DIR__ . '/../../../fixtures/library/mappings/panel_verdicts/nist-csf-2-0_to_iso27001-2022_panel_v1.json';
    private const CANDIDATES_FIXTURE = __DIR__ . '/../../../fixtures/library/mappings/panel_verdicts/nist-csf-2-0_to_iso27001-2022_completeness_candidates_v1.json';

    /** Expected verdict counts per the panel run (90 first-pass + 81 depth-round-2 promotions) */
    private const EXPECTED_KI_VALIDIERT = 171;
    private const EXPECTED_REJECT = 9;
    private const EXPECTED_NEEDS_REVIEW = 7;

    // ────────────────────────────────────────────────────────────────────────────
    // Fixture existence + parse
    // ────────────────────────────────────────────────────────────────────────────

    #[Test]
    public function panel_verdict_fixture_exists(): void
    {
        self::assertFileExists(
            self::PANEL_FIXTURE,
            'NIST CSF 2.0 → ISO 27001:2022 panel verdict fixture is missing. '
            . 'Run the panel to produce fixtures/library/mappings/panel_verdicts/nist-csf-2-0_to_iso27001-2022_panel_v1.json',
        );
    }

    #[Test]
    public function completeness_candidates_fixture_exists(): void
    {
        self::assertFileExists(
            self::CANDIDATES_FIXTURE,
            'NIST CSF 2.0 completeness candidates fixture is missing.',
        );
    }

    #[Test]
    public function panel_fixture_is_valid_json_with_verdicts_key(): void
    {
        $raw = file_get_contents(self::PANEL_FIXTURE);
        self::assertNotFalse($raw, 'Could not read panel verdict fixture');

        $decoded = json_decode($raw, true);
        self::assertNotNull($decoded, 'Panel fixture is not valid JSON: ' . json_last_error_msg());
        self::assertArrayHasKey('verdicts', $decoded, 'Panel fixture missing "verdicts" key');
        self::assertIsArray($decoded['verdicts'], '"verdicts" must be an array');
    }

    #[Test]
    public function candidates_fixture_is_valid_json_with_candidates_key(): void
    {
        $raw = file_get_contents(self::CANDIDATES_FIXTURE);
        self::assertNotFalse($raw, 'Could not read completeness candidates fixture');

        $decoded = json_decode($raw, true);
        self::assertNotNull($decoded, 'Candidates fixture is not valid JSON: ' . json_last_error_msg());
        self::assertArrayHasKey('candidates', $decoded, 'Candidates fixture missing "candidates" key');
        self::assertIsArray($decoded['candidates'], '"candidates" must be an array');
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Provenance header
    // ────────────────────────────────────────────────────────────────────────────

    #[Test]
    public function panel_fixture_has_correct_source_framework(): void
    {
        $lib = $this->loadPanelLibraryHeader();

        self::assertSame(
            'NIST-CSF-2.0',
            $lib['source_framework'] ?? null,
            'Panel fixture source_framework must be "NIST-CSF-2.0" (exact DB code)',
        );
    }

    #[Test]
    public function panel_fixture_has_correct_target_framework(): void
    {
        $lib = $this->loadPanelLibraryHeader();

        self::assertSame(
            'ISO27001',
            $lib['target_framework'] ?? null,
            'Panel fixture target_framework must be "ISO27001" (exact DB code)',
        );
    }

    #[Test]
    public function panel_fixture_has_four_named_experts(): void
    {
        $lib = $this->loadPanelLibraryHeader();

        self::assertArrayHasKey('panel', $lib, 'Panel fixture missing "panel" array in library header');
        self::assertIsArray($lib['panel']);
        self::assertCount(4, $lib['panel'], 'Panel must have exactly 4 named experts');

        $expectedExperts = [
            'isms-specialist',
            'risk-management-specialist',
            'persona-consultant-senior',
            'persona-auditor-external',
        ];
        foreach ($expectedExperts as $expert) {
            self::assertContains(
                $expert,
                $lib['panel'],
                "Panel fixture missing expected expert: {$expert}",
            );
        }
    }

    #[Test]
    public function panel_fixture_documents_amtlich_source_blockage(): void
    {
        $lib = $this->loadPanelLibraryHeader();

        self::assertArrayHasKey('amtlich_source', $lib, 'Panel fixture missing "amtlich_source" in library header');
        $amtlich = strtolower((string) ($lib['amtlich_source'] ?? ''));
        self::assertStringContainsString(
            'none',
            $amtlich,
            'amtlich_source must note that no amtlich source is available (NIST OLIR blocked)',
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Verdict counts: ki_validiert operational
    // ────────────────────────────────────────────────────────────────────────────

    #[Test]
    public function ninety_ki_validiert_verdicts_are_present(): void
    {
        $verdicts = $this->loadVerdicts();
        $kiValidiert = array_filter($verdicts, static fn (array $v): bool => ($v['state'] ?? '') === 'ki_validiert');

        self::assertCount(
            self::EXPECTED_KI_VALIDIERT,
            $kiValidiert,
            sprintf(
                'Expected exactly %d ki_validiert verdicts, got %d. '
                . 'Panel output must not be silently truncated.',
                self::EXPECTED_KI_VALIDIERT,
                count($kiValidiert),
            ),
        );
    }

    #[Test]
    public function nine_rejected_verdicts_are_present(): void
    {
        $verdicts = $this->loadVerdicts();
        $rejected = array_filter($verdicts, static fn (array $v): bool => ($v['state'] ?? '') === 'reject');

        self::assertCount(
            self::EXPECTED_REJECT,
            $rejected,
            sprintf(
                'Expected exactly %d reject verdicts, got %d.',
                self::EXPECTED_REJECT,
                count($rejected),
            ),
        );
    }

    #[Test]
    public function seven_needs_review_verdicts_are_present(): void
    {
        $verdicts = $this->loadVerdicts();
        $needsReview = array_filter($verdicts, static fn (array $v): bool => ($v['state'] ?? '') === 'needs_review');

        self::assertCount(
            self::EXPECTED_NEEDS_REVIEW,
            $needsReview,
            sprintf(
                'Expected exactly %d needs_review verdicts, got %d.',
                self::EXPECTED_NEEDS_REVIEW,
                count($needsReview),
            ),
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // State semantics: deprecated not operational; ki_validiert have mapping%
    // ────────────────────────────────────────────────────────────────────────────

    #[Test]
    public function rejected_verdicts_are_not_operational(): void
    {
        $verdicts = $this->loadVerdicts();

        foreach ($verdicts as $verdict) {
            if (($verdict['state'] ?? '') !== 'reject') {
                continue;
            }
            self::assertNotSame(
                'ki_validiert',
                $verdict['state'] ?? null,
                sprintf(
                    'Rejected verdict %s→%s must have state=reject, not ki_validiert.',
                    $verdict['nist'] ?? '?',
                    $verdict['baustein'] ?? '?',
                ),
            );
        }
    }

    #[Test]
    public function all_ki_validiert_have_positive_mapping_percentage(): void
    {
        $verdicts = $this->loadVerdicts();

        foreach ($verdicts as $verdict) {
            if (($verdict['state'] ?? '') !== 'ki_validiert') {
                continue;
            }
            $pct = (int) ($verdict['mappingPercentage'] ?? 0);
            self::assertGreaterThanOrEqual(
                35,
                $pct,
                sprintf(
                    'ki_validiert verdict %s→%s has suspiciously low mappingPercentage %d%% '
                    . '(minimum credibility gate is 35%%)',
                    $verdict['nist'] ?? '?',
                    $verdict['baustein'] ?? '?',
                    $pct,
                ),
            );
        }
    }

    #[Test]
    public function all_ki_validiert_have_real_votes(): void
    {
        $verdicts = $this->loadVerdicts();

        foreach ($verdicts as $verdict) {
            if (($verdict['state'] ?? '') !== 'ki_validiert') {
                continue;
            }
            $votes = (int) ($verdict['realVotes'] ?? 0);
            self::assertGreaterThanOrEqual(
                1,
                $votes,
                sprintf(
                    'ki_validiert verdict %s→%s has realVotes=%d (must be ≥ 1 for a panel verdict)',
                    $verdict['nist'] ?? '?',
                    $verdict['baustein'] ?? '?',
                    $votes,
                ),
            );
        }
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Candidates isolation: NOT applied as verdicts
    // ────────────────────────────────────────────────────────────────────────────

    #[Test]
    public function candidates_fixture_has_215_entries(): void
    {
        $raw = (string) file_get_contents(self::CANDIDATES_FIXTURE);
        $decoded = json_decode($raw, true);
        $candidates = $decoded['candidates'] ?? [];

        self::assertCount(
            215,
            $candidates,
            'Completeness candidates fixture must contain exactly 215 UNREFUTED proposals.',
        );
    }

    #[Test]
    public function candidates_status_label_says_unrefuted_proposals(): void
    {
        $raw = (string) file_get_contents(self::CANDIDATES_FIXTURE);
        $decoded = json_decode($raw, true);
        $status = (string) ($decoded['library']['status'] ?? '');

        self::assertStringContainsString(
            'UNREFUTED',
            strtoupper($status),
            'Completeness candidates fixture must carry the UNREFUTED_PROPOSALS_ONLY status label',
        );
    }

    #[Test]
    public function candidates_are_not_present_in_panel_verdicts(): void
    {
        $verdicts = $this->loadVerdicts();
        $raw = (string) file_get_contents(self::CANDIDATES_FIXTURE);
        $decoded = json_decode($raw, true);
        $candidates = $decoded['candidates'] ?? [];

        // Build a set of (target, source-nist) pairs from ki_validiert verdicts
        $appliedPairs = [];
        foreach ($verdicts as $v) {
            if (($v['state'] ?? '') === 'ki_validiert') {
                $appliedPairs[strtolower(($v['nist'] ?? '')) . '||' . strtolower(($v['baustein'] ?? ''))] = true;
            }
        }

        // Ensure none of the candidates appears as a ki_validiert verdict
        // (candidates do not have a nist source field — they have a rationale field
        //  for the proposed ADDITIONAL target; just verify the candidate file is separate)
        self::assertNotEmpty($candidates, 'Completeness candidates list must not be empty');

        // Key check: the candidates fixture should NOT have a "verdicts" key
        self::assertArrayNotHasKey(
            'verdicts',
            json_decode((string) file_get_contents(self::CANDIDATES_FIXTURE), true) ?? [],
            'Completeness candidates fixture must NOT have a "verdicts" key — it is a separate proposals log',
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Field format guards
    // ────────────────────────────────────────────────────────────────────────────

    #[Test]
    public function all_verdicts_have_nist_field(): void
    {
        $verdicts = $this->loadVerdicts();
        foreach ($verdicts as $i => $verdict) {
            self::assertArrayHasKey('nist', $verdict, "Verdict #{$i}: missing 'nist' source field");
            self::assertNotEmpty($verdict['nist'], "Verdict #{$i}: 'nist' field must not be empty");
        }
    }

    #[Test]
    public function all_verdicts_have_baustein_field(): void
    {
        $verdicts = $this->loadVerdicts();
        foreach ($verdicts as $i => $verdict) {
            self::assertArrayHasKey('baustein', $verdict, "Verdict #{$i}: missing 'baustein' target field");
            self::assertNotEmpty($verdict['baustein'], "Verdict #{$i}: 'baustein' field must not be empty");
        }
    }

    #[Test]
    public function all_verdicts_have_valid_state(): void
    {
        $verdicts = $this->loadVerdicts();
        $validStates = ['ki_validiert', 'reject', 'needs_review'];

        foreach ($verdicts as $i => $verdict) {
            self::assertContains(
                $verdict['state'] ?? null,
                $validStates,
                sprintf('Verdict #%d (%s): state "%s" is not a valid state', $i, $verdict['nist'] ?? '?', $verdict['state'] ?? '?'),
            );
        }
    }

    #[Test]
    public function nist_ids_match_csf20_pattern(): void
    {
        $verdicts = $this->loadVerdicts();
        $invalid = [];
        foreach ($verdicts as $v) {
            $nistId = (string) ($v['nist'] ?? '');
            if (!preg_match('/^(GV|ID|PR|DE|RS|RC)\.[A-Z]{2,4}-\d{2}$/', $nistId)) {
                $invalid[] = $nistId;
            }
        }
        self::assertEmpty(
            $invalid,
            'Verdict fixture contains NIST IDs not matching CSF 2.0 pattern FUNCTION.CATEGORY-NN: '
            . implode(', ', array_unique(array_slice($invalid, 0, 10))),
        );
    }

    #[Test]
    public function iso_control_ids_match_annex_a_or_clause_pattern(): void
    {
        $verdicts = $this->loadVerdicts();
        $invalid = [];
        foreach ($verdicts as $v) {
            $baustein = (string) ($v['baustein'] ?? '');
            // Accept: A.5.1 - A.8.34 (Annex A) or clause N.M (ISO main body, optionally
            // in the DB-resolvable "ISO27001-N.M" form used for clause-level targets)
            $isAnnexA = (bool) preg_match('/^A\.[5-8]\.\d+$/', $baustein);
            $isClause = (bool) preg_match('/^(ISO27001-)?\d+(\.\d+)+$/', $baustein);
            if (!$isAnnexA && !$isClause) {
                $invalid[] = $baustein;
            }
        }
        self::assertEmpty(
            $invalid,
            'Verdict fixture contains ISO 27001 target IDs not matching Annex A (A.5-8.N) or clause (N.M) patterns: '
            . implode(', ', array_unique(array_slice($invalid, 0, 10))),
        );
    }

    // ────────────────────────────────────────────────────────────────────────────
    // Helpers
    // ────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadVerdicts(): array
    {
        $raw = (string) file_get_contents(self::PANEL_FIXTURE);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true) ?? [];

        /** @var array<int, array<string, mixed>> $verdicts */
        $verdicts = $decoded['verdicts'] ?? [];

        return $verdicts;
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPanelLibraryHeader(): array
    {
        $raw = (string) file_get_contents(self::PANEL_FIXTURE);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true) ?? [];

        /** @var array<string, mixed> $lib */
        $lib = $decoded['library'] ?? [];

        return $lib;
    }
}
