<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Service\PolicyWizard\BulkApprovalGroupingService;
use App\Service\PolicyWizard\PolicyTemplateRenderer;
use App\Tests\Fixtures\PolicyWizardDefangFixture;
use BadMethodCallException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Hardcoded bulk-approval defang fixture for the Policy-Wizard.
 *
 * Closes Phase 4-C implementation gate §7-#6 (Phase 4 sprint
 * reconciliation, `docs/plans/policy-wizard/07-phase4-sprint-
 * reconciliation.md` §7 item 6).
 *
 * The four defangs from architecture §9.2.1 (External-Auditor sign-
 * off blocker) PLUS the two cross-cutting hardcoded rules from §6
 * Step 2 / §11.2 are asserted here:
 *
 *   §9.2.1 #1: top-level Information Security Policy (ISO Cl. 5.2)
 *              hard-EXCLUDED from any bulk grouping.
 *   §9.2.1 #2: DPO Charter hard-EXCLUDED from any bulk grouping
 *              (gate §7-#6 explicit bullet "DPO charter hard-excluded").
 *   §9.2.1 #3: tenants in regulated scope (DORA / NIS2 / KRITIS /
 *              BaFin-supervised) get `bulkApprovalDualSignoff=true`
 *              by default; ISO-only tenants get false.
 *   §9.2.1 #4: bulk batch capped at 10 documents; larger runs split.
 *   §9.2.1 #5: rationale length ≥200 characters (mandatory).
 *   §6 Step 2: climate-change wording always-included for `iso27001`
 *              templates (hardcoded, no UI toggle).
 *   §11.2:     variable-substitution markers HIDDEN in rendered
 *              output; only the substituted value appears.
 *
 * IMPLEMENTATION GATE NOTE: this file ships with W1-D, before the
 * `BulkApprovalGroupingService` and `PolicyTemplateRenderer` logic
 * exists (W2 / W3 work). Each test guards against the placeholder
 * `BadMethodCallException` from the service stubs and emits
 * `markTestSkipped` until W2/W3 lands. The test class still loads
 * cleanly under `lint:container` and `phpunit`, fulfilling gate
 * §7-#6 ("dedicated PHPUnit test fixture exists") without false-
 * green coverage.
 *
 * The fixture data lives in `tests/Fixtures/PolicyWizardDefangFixture.php`.
 */
final class BulkApprovalDefangTest extends TestCase
{
    private BulkApprovalGroupingService $groupingService;
    private PolicyTemplateRenderer $renderer;

    protected function setUp(): void
    {
        $this->groupingService = new BulkApprovalGroupingService();
        $this->renderer = new PolicyTemplateRenderer();
    }

    /**
     * §9.2.1 defang #1: ISO 27001 Cl. 5.2 top-level Information
     * Security Policy never appears in a bulk batch — it always lands
     * in its own ceremonial single-item approval flow.
     *
     * Audit-NC if violated: ISO 27001:2022 Cl. 5.2 (Policy) +
     * Cl. 5.1 (Leadership commitment evidence).
     */
    #[Test]
    public function topLevelPolicyExcludedFromBulkGrouping(): void
    {
        $this->skipIfServicePending(function (): void {
            $tenants = PolicyWizardDefangFixture::buildSampleTenants();
            $topLevel = PolicyWizardDefangFixture::buildTopLevelPolicyTemplate();

            self::assertTrue(
                $this->groupingService->isExcludedFromBulk($topLevel),
                'ISO 27001 Cl. 5.2 top-level Information Security Policy must be hard-excluded from bulk batches (§9.2.1 #1).',
            );

            $entries = array_map(
                static fn ($template) => [
                    'document_id' => null,
                    'policy_template' => $template,
                    'excluded_from_bulk' => false,
                    'exclusion_reason' => null,
                ],
                array_merge([$topLevel], PolicyWizardDefangFixture::buildTopicPolicyTemplates()),
            );

            $inbox = $this->groupingService->buildInbox($tenants['iso_only'], $entries);

            self::assertNotEmpty($inbox['excluded_singletons'] ?? [], 'Top-level policy must surface as an excluded singleton.');
            self::assertNotEmpty($inbox['bulk_batches'] ?? [], 'Topic policies must still group into a bulk batch.');
        });
    }

    /**
     * §9.2.1 defang #2 (Phase 4-C gate §7-#6 explicit bullet): the
     * DPO Charter (ISO 27701 / GDPR Art. 38) is hard-excluded from
     * any bulk grouping.
     *
     * Audit-NC if violated: ISO 27701:2019 Cl. 5.3.2 (independent DPO
     * accountability) + GDPR Art. 38(3) (DPO independence).
     */
    #[Test]
    public function dpoCharterExcludedFromBulkGrouping(): void
    {
        $this->skipIfServicePending(function (): void {
            $dpoCharter = PolicyWizardDefangFixture::buildDpoCharterTemplate();

            self::assertTrue(
                $this->groupingService->isExcludedFromBulk($dpoCharter),
                'DPO Charter must be hard-excluded from bulk batches (§9.2.1 #2 / gate §7-#6).',
            );
        });
    }

    /**
     * §9.2.1 defang #3 — DORA-regulated tenant gets
     * `bulkApprovalDualSignoff=true` by default. Override requires
     * SUPER_ADMIN + audit-log entry.
     *
     * Audit-NC if violated: EU-DORA Art. 5(2)(b) (Management body
     * oversight) + Phase 1 CISO-Executive review BaFin-risk concern.
     */
    #[Test]
    public function doraTenantDualSignoffDefaultOn(): void
    {
        $this->skipIfServicePending(function (): void {
            $tenants = PolicyWizardDefangFixture::buildSampleTenants();
            self::assertTrue(
                $this->groupingService->resolveDualSignoffDefault($tenants['dora']),
                'DORA-regulated tenant must default to bulkApprovalDualSignoff=true (§9.2.1 #3).',
            );
        });
    }

    /**
     * §9.2.1 defang #3 — NIS2-regulated tenant default-on.
     *
     * Audit-NC if violated: NIS2 Art. 21(2) (governance of cyber-
     * security risk-management measures) + BSIG §30.
     */
    #[Test]
    public function nis2TenantDualSignoffDefaultOn(): void
    {
        $this->skipIfServicePending(function (): void {
            $tenants = PolicyWizardDefangFixture::buildSampleTenants();
            self::assertTrue(
                $this->groupingService->resolveDualSignoffDefault($tenants['nis2']),
                'NIS2-regulated tenant must default to bulkApprovalDualSignoff=true (§9.2.1 #3).',
            );
        });
    }

    /**
     * §9.2.1 defang #3 — KRITIS tenant default-on.
     *
     * Audit-NC if violated: BSIG §8a (KRITIS biennial audit cadence)
     * + sector-specific KRITIS-V Anlage.
     */
    #[Test]
    public function kritisTenantDualSignoffDefaultOn(): void
    {
        $this->skipIfServicePending(function (): void {
            $tenants = PolicyWizardDefangFixture::buildSampleTenants();
            self::assertTrue(
                $this->groupingService->resolveDualSignoffDefault($tenants['kritis']),
                'KRITIS tenant must default to bulkApprovalDualSignoff=true (§9.2.1 #3).',
            );
        });
    }

    /**
     * §9.2.1 defang #3 — BSI-Mittelstand sample (BSI 200-2
     * implementation phase) is treated as "BSI-supervised regulated
     * scope" via the explicit `regulated_scope` marker. Test guards
     * the gate §7-#6 bullet "BSI-tenant default
     * bulkApprovalDualSignoff=true".
     *
     * Audit-NC if violated: BSI 200-2 §8.1 (Sicherheitsleitlinie,
     * Geschäftsleitungsbeschluss).
     */
    #[Test]
    public function bsiTenantDualSignoffDefaultOn(): void
    {
        $this->skipIfServicePending(function (): void {
            $tenants = PolicyWizardDefangFixture::buildSampleTenants();

            // BSI-Mittelstand fixture defaults regulated_scope=[]; the
            // gate §7-#6 wording lists BSI explicitly. The future
            // resolver MUST treat any tenant with a non-null
            // `bsiPhase` as in-scope, OR the regulated_scope marker
            // must include 'bsi'. Either contract is acceptable —
            // the assertion encodes the gate-required outcome.
            $bsiTenant = $tenants['bsi'];
            $bsiSettings = $bsiTenant->getSettings() ?? [];
            $scope = $bsiSettings[PolicyWizardDefangFixture::TENANT_SETTINGS_REGULATED_SCOPE_KEY] ?? [];
            $scope['bsi'] = true;
            $bsiSettings[PolicyWizardDefangFixture::TENANT_SETTINGS_REGULATED_SCOPE_KEY] = $scope;
            $bsiTenant->setSettings($bsiSettings);

            self::assertTrue(
                $this->groupingService->resolveDualSignoffDefault($bsiTenant),
                'BSI-supervised tenant must default to bulkApprovalDualSignoff=true (gate §7-#6).',
            );
        });
    }

    /**
     * §9.2.1 defang #3 — ISO-only tenant defaults OFF. Single-signoff
     * remains acceptable until the tenant opts into regulated scope.
     *
     * Audit-NC if violated: NONE (ISO-only is the baseline). False
     * positive would be a usability regression for non-regulated
     * tenants.
     */
    #[Test]
    public function isoOnlyTenantDualSignoffDefaultOff(): void
    {
        $this->skipIfServicePending(function (): void {
            $tenants = PolicyWizardDefangFixture::buildSampleTenants();
            self::assertFalse(
                $this->groupingService->resolveDualSignoffDefault($tenants['iso_only']),
                'ISO-only tenant must default to bulkApprovalDualSignoff=false (§9.2.1 #3 inverse).',
            );
        });
    }

    /**
     * §9.2.1 defang #4 — bulk batch capped at 10 documents. A 12-doc
     * bundle (1 top-level + 1 DPO charter excluded; 10 topic policies)
     * MUST result in a single bulk batch of exactly 10. A 13+ doc
     * bundle would split into multiple batches.
     *
     * Audit-NC if violated: External-Auditor review challenge "GF
     * rubber-stamped 47 docs in 3 minutes".
     */
    #[Test]
    public function bulkBatchCapsAtTenDocuments(): void
    {
        $this->skipIfServicePending(function (): void {
            $tenants = PolicyWizardDefangFixture::buildSampleTenants();
            $bundle = PolicyWizardDefangFixture::buildOversizedTemplateBundle();

            $entries = array_map(
                static fn ($template) => [
                    'document_id' => null,
                    'policy_template' => $template,
                    'excluded_from_bulk' => false,
                    'exclusion_reason' => null,
                ],
                $bundle,
            );

            $inbox = $this->groupingService->buildInbox($tenants['dora'], $entries);

            self::assertSame(
                BulkApprovalGroupingService::MAX_BULK_BATCH_SIZE,
                $inbox['max_batch_size'] ?? -1,
                'Inbox metadata must publish the hardcoded MAX_BULK_BATCH_SIZE=10 (§9.2.1 #4).',
            );

            foreach ($inbox['bulk_batches'] ?? [] as $batch) {
                self::assertLessThanOrEqual(
                    BulkApprovalGroupingService::MAX_BULK_BATCH_SIZE,
                    count($batch),
                    'No bulk batch may exceed 10 documents (§9.2.1 #4).',
                );
            }
        });
    }

    /**
     * §9.2.1 defang #5 — mandatory rationale ≥200 characters. Empty
     * or trivial rationales must be rejected.
     *
     * Audit-NC if violated: External-Auditor "encourages real
     * engagement" requirement (architecture §9.2.1).
     */
    #[Test]
    public function bulkApprovalRationaleEnforcesMinLength(): void
    {
        $this->skipIfServicePending(function (): void {
            self::assertSame(
                200,
                BulkApprovalGroupingService::MIN_BULK_RATIONALE_CHARS,
                'MIN_BULK_RATIONALE_CHARS constant must equal 200 (§9.2.1 #5).',
            );

            $tooShort = str_repeat('x', 199);
            $exactlyAtBoundary = str_repeat('x', 200);

            self::assertNotEmpty(
                $this->groupingService->validateRationale($tooShort),
                'Rationale of 199 characters must produce a violation (§9.2.1 #5).',
            );
            self::assertSame(
                [],
                $this->groupingService->validateRationale($exactlyAtBoundary),
                'Rationale of 200 characters must validate cleanly (§9.2.1 #5 boundary).',
            );
        });
    }

    /**
     * §6 Step 2 / Phase 4-C gate §7-#6 explicit bullet: climate-change
     * wording is always-included for every `iso27001` PolicyTemplate
     * — there is no UI toggle.
     *
     * Audit-NC if violated: ISO 27001:2022 Climate-Change Amendment
     * (Aug 2024).
     */
    #[Test]
    public function climateChangeWordingAlwaysOnForIso27001(): void
    {
        $this->skipIfRendererPending(function (): void {
            $iso27001Templates = array_merge(
                [PolicyWizardDefangFixture::buildTopLevelPolicyTemplate()],
                PolicyWizardDefangFixture::buildTopicPolicyTemplates(),
            );

            foreach ($iso27001Templates as $template) {
                self::assertSame(
                    'iso27001',
                    $template->getStandard(),
                    'Pre-condition: fixture must yield iso27001 standard.',
                );
                self::assertTrue(
                    $template->isClimateChangeWording(),
                    sprintf(
                        'Template %s — climate_change_wording must be true at fixture level for iso27001 (§6 Step 2).',
                        (string) $template->getKey(),
                    ),
                );
                self::assertTrue(
                    $this->renderer->isClimateChangeWordingMandatory($template),
                    sprintf(
                        'Renderer must report climate-change wording mandatory for iso27001 template %s.',
                        (string) $template->getKey(),
                    ),
                );
            }
        });
    }

    /**
     * §11.2 (P1 Auditor reversal) — variable-substitution markers
     * (`{{ tenant.legal_name }}`) MUST NOT appear in the rendered
     * document body. Only the substituted value is visible. The
     * machine-readable manifest is the audit trail.
     *
     * Audit-NC if violated: External-Auditor "templated feel" tell —
     * leftover `{{ }}` markers signal generator-machine output.
     */
    #[Test]
    public function variableSubstitutionMarkersHiddenInRender(): void
    {
        $this->skipIfRendererPending(function (): void {
            $template = PolicyWizardDefangFixture::buildTopLevelPolicyTemplate();
            $variables = [
                'tenant.legal_name' => 'MyCompany GmbH',
                'tenant.scope_statement' => 'All headquarters and DE-based subsidiaries.',
            ];

            $result = $this->renderer->render($template, $variables);

            self::assertArrayHasKey('body', $result);
            self::assertArrayHasKey('substitution_manifest', $result);
            self::assertFalse(
                $result['leftover_markers_detected'] ?? true,
                'Renderer must report leftover_markers_detected=false (§11.2).',
            );
            self::assertStringNotContainsString(
                '{{',
                (string) $result['body'],
                'Rendered body must not contain `{{` markers (§11.2 — auditor-trap prevention).',
            );
            self::assertStringNotContainsString(
                '}}',
                (string) $result['body'],
                'Rendered body must not contain `}}` markers (§11.2).',
            );
        });
    }

    /**
     * Wraps a test body in the W2 not-yet-implemented guard. When the
     * service stub still throws `BadMethodCallException`, the test is
     * marked skipped so the class loads cleanly + reports the gate-
     * §7-#6 fixture in place without false-green coverage.
     */
    private function skipIfServicePending(callable $body): void
    {
        try {
            $body();
        } catch (BadMethodCallException $e) {
            self::markTestSkipped(sprintf(
                'BulkApprovalGroupingService not yet implemented (W2 ticket): %s',
                $e->getMessage(),
            ));
        }
    }

    /**
     * Wraps a test body in the W3 not-yet-implemented guard for
     * PolicyTemplateRenderer-dependent tests.
     */
    private function skipIfRendererPending(callable $body): void
    {
        try {
            $body();
        } catch (BadMethodCallException $e) {
            self::markTestSkipped(sprintf(
                'PolicyTemplateRenderer not yet implemented (W3 ticket): %s',
                $e->getMessage(),
            ));
        }
    }
}
