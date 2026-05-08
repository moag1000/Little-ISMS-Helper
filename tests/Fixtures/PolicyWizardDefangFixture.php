<?php

declare(strict_types=1);

namespace App\Tests\Fixtures;

use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Service\PolicyWizard\BulkApprovalGroupingService;

/**
 * In-memory builders for the Policy-Wizard bulk-approval defang test
 * suite. Backs `tests/Service/PolicyWizard/BulkApprovalDefangTest.php`.
 *
 * Implements the data-provider contract referenced by Phase 4-C
 * implementation gate §7-#6 and architecture §9.2.1 — produces the
 * minimum tenant + policy-template fixtures needed to assert the four
 * hardcoded bulk-approval defangs (top-level Cl. 5.2 exclusion, DPO
 * Charter exclusion, regulated-scope dual-signoff default, batch cap
 * ≤10) plus the climate-change-wording always-on rule and the
 * mandatory rationale length.
 *
 * The fixtures DO NOT touch Doctrine — every entity is hydrated via
 * setters so the suite runs as a plain PHPUnit `TestCase` (no
 * `KernelTestCase` overhead). Tenant `regulated_scope` is encoded via
 * the `settings` JSON column under the `policy_wizard.regulated_scope`
 * key, which is the contract the future
 * `BulkApprovalGroupingService::resolveDualSignoffDefault` reads. The
 * key list mirrors §9.2.1 verbatim: dora | nis2 | kritis | bafin.
 *
 * @phpstan-type RegulatedScope array{
 *   dora?: bool,
 *   nis2?: bool,
 *   kritis?: bool,
 *   bafin?: bool,
 * }
 */
final class PolicyWizardDefangFixture
{
    /**
     * Tenant settings key under which the regulated_scope marker is
     * stored. Read by the (future) BulkApprovalGroupingService.
     */
    public const TENANT_SETTINGS_REGULATED_SCOPE_KEY = 'policy_wizard.regulated_scope';

    /**
     * Build the four sample tenants used by the defang test suite.
     *
     * @return array{
     *   iso_only: Tenant,
     *   dora: Tenant,
     *   nis2: Tenant,
     *   kritis: Tenant,
     *   bsi: Tenant,
     * }
     */
    public static function buildSampleTenants(): array
    {
        return [
            'iso_only' => self::makeTenant(
                code: 'TEST_ISO',
                name: 'ISO-only Sample Tenant',
                regulatedScope: [],
            ),
            'dora' => self::makeTenant(
                code: 'TEST_DORA',
                name: 'DORA-regulated Sample Tenant',
                regulatedScope: ['dora' => true, 'bafin' => true],
            ),
            'nis2' => self::makeTenant(
                code: 'TEST_NIS2',
                name: 'NIS2-regulated Sample Tenant',
                regulatedScope: ['nis2' => true],
                nis2Classification: Tenant::NIS2_ESSENTIAL,
            ),
            'kritis' => self::makeTenant(
                code: 'TEST_KRITIS',
                name: 'KRITIS Sample Tenant',
                regulatedScope: ['kritis' => true, 'nis2' => true],
                nis2Classification: Tenant::NIS2_ESSENTIAL,
            ),
            'bsi' => self::makeTenant(
                code: 'TEST_BSI',
                name: 'BSI-Mittelstand Sample Tenant',
                regulatedScope: [],
                bsiPhase: Tenant::BSI_PHASE_IMPLEMENTATION,
            ),
        ];
    }

    /**
     * Top-level ISO 27001 Information Security Policy (Cl. 5.2).
     * Hard-excluded from any bulk grouping per §9.2.1 defang #1.
     */
    public static function buildTopLevelPolicyTemplate(): PolicyTemplate
    {
        return self::makeTemplate(
            key: BulkApprovalGroupingService::TOP_LEVEL_POLICY_KEY,
            standard: 'iso27001',
            topic: 'information_security_policy',
            documentType: 'policy',
            normRef: 'ISO 27001:2022 Cl. 5.2',
            climateChangeWording: true,
            dpoSectionRequired: false,
        );
    }

    /**
     * DPO Charter (ISO 27701 / GDPR Art. 38).
     * Hard-excluded from any bulk grouping per §9.2.1 defang #2.
     */
    public static function buildDpoCharterTemplate(): PolicyTemplate
    {
        return self::makeTemplate(
            key: BulkApprovalGroupingService::DPO_CHARTER_KEY,
            standard: 'iso27701',
            topic: 'dpo_charter',
            documentType: 'policy',
            normRef: 'GDPR Art. 38',
            climateChangeWording: false,
            dpoSectionRequired: true,
        );
    }

    /**
     * Five sample ISO 27001 topic policies. All are eligible for
     * bulk grouping (none of the §9.2.1 exclusions apply).
     *
     * @return list<PolicyTemplate>
     */
    public static function buildTopicPolicyTemplates(): array
    {
        return [
            self::makeTemplate(
                key: 'iso27001.access_control',
                standard: 'iso27001',
                topic: 'access_control',
                documentType: 'policy',
                normRef: 'A.5.15',
                climateChangeWording: true,
                dpoSectionRequired: false,
            ),
            self::makeTemplate(
                key: 'iso27001.cryptography',
                standard: 'iso27001',
                topic: 'cryptography',
                documentType: 'policy',
                normRef: 'A.8.24',
                climateChangeWording: true,
                dpoSectionRequired: false,
            ),
            self::makeTemplate(
                key: 'iso27001.supplier_relationships',
                standard: 'iso27001',
                topic: 'supplier_relationships',
                documentType: 'policy',
                normRef: 'A.5.19',
                climateChangeWording: true,
                dpoSectionRequired: false,
            ),
            self::makeTemplate(
                key: 'iso27001.incident_management',
                standard: 'iso27001',
                topic: 'incident_management',
                documentType: 'procedure',
                normRef: 'A.5.24',
                climateChangeWording: true,
                dpoSectionRequired: false,
            ),
            self::makeTemplate(
                key: 'iso27001.business_continuity',
                standard: 'iso27001',
                topic: 'business_continuity',
                documentType: 'plan',
                normRef: 'A.5.30',
                climateChangeWording: true,
                dpoSectionRequired: false,
            ),
        ];
    }

    /**
     * Build a 12-document fixture set (1 top-level, 1 DPO charter, 10
     * topic policies) for the batch-cap test (defang #4 — max 10 per
     * batch must split into at least two batches).
     *
     * @return list<PolicyTemplate>
     */
    public static function buildOversizedTemplateBundle(): array
    {
        $bundle = [self::buildTopLevelPolicyTemplate(), self::buildDpoCharterTemplate()];

        for ($i = 1; $i <= 10; $i++) {
            $bundle[] = self::makeTemplate(
                key: sprintf('iso27001.bulk_topic_%02d', $i),
                standard: 'iso27001',
                topic: sprintf('bulk_topic_%02d', $i),
                documentType: 'policy',
                normRef: sprintf('A.5.%02d', $i),
                climateChangeWording: true,
                dpoSectionRequired: false,
            );
        }

        return $bundle;
    }

    /**
     * Helper for tests: returns the regulated-scope map for a tenant
     * built by this fixture. Encoded under
     * `tenant.settings[policy_wizard.regulated_scope]`.
     *
     * @return array<string, bool>
     */
    public static function readRegulatedScope(Tenant $tenant): array
    {
        $settings = $tenant->getSettings();
        if (!is_array($settings)) {
            return [];
        }

        $scope = $settings[self::TENANT_SETTINGS_REGULATED_SCOPE_KEY] ?? [];
        if (!is_array($scope)) {
            return [];
        }

        /** @var array<string, bool> $scope */
        return $scope;
    }

    /**
     * @param array<string, bool> $regulatedScope
     */
    private static function makeTenant(
        string $code,
        string $name,
        array $regulatedScope,
        ?string $nis2Classification = null,
        ?string $bsiPhase = null,
    ): Tenant {
        $tenant = new Tenant();
        $tenant->setCode($code);
        $tenant->setName($name);
        $tenant->setSettings([
            self::TENANT_SETTINGS_REGULATED_SCOPE_KEY => $regulatedScope,
        ]);

        if ($nis2Classification !== null) {
            $tenant->setNis2Classification($nis2Classification);
        }
        if ($bsiPhase !== null) {
            $tenant->setBsiPhase($bsiPhase);
        }

        return $tenant;
    }

    private static function makeTemplate(
        string $key,
        string $standard,
        string $topic,
        string $documentType,
        ?string $normRef,
        bool $climateChangeWording,
        bool $dpoSectionRequired,
    ): PolicyTemplate {
        $template = new PolicyTemplate();
        $template->setKey($key);
        $template->setStandard($standard);
        $template->setTopic($topic);
        $template->setDocumentType($documentType);
        $template->setNormRef($normRef);
        $template->setTitleTranslationKey(sprintf('policy.%s.title', $key));
        $template->setBodyTranslationKey(sprintf('policy.%s.body', $key));
        $template->setClimateChangeWording($climateChangeWording);
        $template->setDpoSectionRequired($dpoSectionRequired);

        return $template;
    }
}
