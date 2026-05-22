<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\CategoryProvider;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiBaselineCoverageCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiIsmsConceptPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiKritisFlagDocumentedCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiSchutzbedarfMethodPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiTierConsistencyCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\BsiTopLevelLeitliniePresentCheck;
use App\Service\TenantContext;

/**
 * BsiFrameworkCategoryProvider
 *
 * Extracted from ComplianceWizardService (god-class decomposition).
 * Provides category definitions for BSI frameworks:
 * - BSI IT-Grundschutz (Basis-Absicherung)
 * - BSI IT-Grundschutz (Standard-Absicherung)
 * - BSI IT-Grundschutz (Kern-Absicherung)
 * - BSI C5:2020 Cloud Compliance
 * - BSI C5:2026 Cloud Compliance Criteria Catalogue
 */
final class BsiFrameworkCategoryProvider
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ?\App\Repository\TenantPolicySettingRepository $tenantPolicySettingRepository = null,
    ) {
    }

    /**
     * BSI IT-Grundschutz (Basis-Absicherung) Categories
     *
     * Based on the 10 layers of the BSI IT-Grundschutz-Kompendium:
     * ISMS, ORP, CON, OPS, DET, APP, SYS, IND, NET, INF
     */
    public function getBsiGrundschutzCategories(): array
    {
        $categories = [
            'isms' => [
                'name' => 'wizard.bsi_grundschutz.isms',
                'description' => 'wizard.bsi_grundschutz.isms_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.isms_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.isms_enhanced',
                'icon' => 'shield-check',
                'weight' => 2,
                'checks' => [
                    'bsi_grundschutz_isms' => [
                        'name' => 'wizard.check.bsi_grundschutz_isms',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_grundschutz_isms_desc',
                        'route' => 'app_context_edit',
                    ],
                ],
            ],
            'orp' => [
                'name' => 'wizard.bsi_grundschutz.orp',
                'description' => 'wizard.bsi_grundschutz.orp_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.orp_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.orp_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'bsi_grundschutz_orp' => [
                        'name' => 'wizard.check.bsi_grundschutz_orp',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_grundschutz_orp_desc',
                        'route' => 'app_context_edit',
                    ],
                ],
            ],
            'con' => [
                'name' => 'wizard.bsi_grundschutz.con',
                'description' => 'wizard.bsi_grundschutz.con_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.con_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.con_enhanced',
                'icon' => 'nav-file-earmark-text',
                'weight' => 1.5,
                'checks' => [
                    'bsi_grundschutz_con' => [
                        'name' => 'wizard.check.bsi_grundschutz_con',
                        'type' => 'document_review',
                        'document_categories' => ['policy', 'concept'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'ops' => [
                'name' => 'wizard.bsi_grundschutz.ops',
                'description' => 'wizard.bsi_grundschutz.ops_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.ops_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.ops_enhanced',
                'icon' => 'nav-gear',
                'weight' => 2,
                'checks' => [
                    'bsi_grundschutz_ops' => [
                        'name' => 'wizard.check.bsi_grundschutz_ops',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'det' => [
                'name' => 'wizard.bsi_grundschutz.det',
                'description' => 'wizard.bsi_grundschutz.det_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.det_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.det_enhanced',
                'icon' => 'ui-search',
                'weight' => 1.5,
                'checks' => [
                    'bsi_grundschutz_det' => [
                        'name' => 'wizard.check.bsi_grundschutz_det',
                        'type' => 'incident_process',
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'app' => [
                'name' => 'wizard.bsi_grundschutz.app',
                'description' => 'wizard.bsi_grundschutz.app_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.app_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.app_enhanced',
                'icon' => 'asset-application',
                'weight' => 2,
                'checks' => [
                    'bsi_grundschutz_app' => [
                        'name' => 'wizard.check.bsi_grundschutz_app',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'sys' => [
                'name' => 'wizard.bsi_grundschutz.sys',
                'description' => 'wizard.bsi_grundschutz.sys_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.sys_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.sys_enhanced',
                'icon' => 'asset-endpoint',
                'weight' => 2,
                'checks' => [
                    'bsi_grundschutz_sys' => [
                        'name' => 'wizard.check.bsi_grundschutz_sys',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'ind' => [
                'name' => 'wizard.bsi_grundschutz.ind',
                'description' => 'wizard.bsi_grundschutz.ind_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.ind_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.ind_enhanced',
                'icon' => 'cpu',
                'weight' => 1,
                'checks' => [
                    'bsi_grundschutz_ind' => [
                        'name' => 'wizard.check.bsi_grundschutz_ind',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_grundschutz_ind_desc',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'net' => [
                'name' => 'wizard.bsi_grundschutz.net',
                'description' => 'wizard.bsi_grundschutz.net_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.net_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.net_enhanced',
                'icon' => 'asset-network',
                'weight' => 1.5,
                'checks' => [
                    'bsi_grundschutz_net' => [
                        'name' => 'wizard.check.bsi_grundschutz_net',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'inf' => [
                'name' => 'wizard.bsi_grundschutz.inf',
                'description' => 'wizard.bsi_grundschutz.inf_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz.inf_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz.inf_enhanced',
                'icon' => 'nav-building',
                'weight' => 1.5,
                'checks' => [
                    'bsi_grundschutz_inf' => [
                        'name' => 'wizard.check.bsi_grundschutz_inf',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_grundschutz_inf_desc',
                        'route' => 'app_location_index',
                    ],
                ],
            ],
            // Policy-Wizard outputs — BSI 200-1/2/3/4 policies, baseline coverage,
            // tier-consistency, KRITIS-applicability. Gated on BSI scope being
            // active (ComplianceFramework code 'BSI_GRUNDSCHUTZ' active OR
            // tenant policy setting 'org.targets_bsi_certification' true).
            // Returns null when out-of-scope; array_filter() drops the row.
            'bsi_policies' => $this->buildBsiPolicyWizardCategory(),
        ];

        // Drop categories that opted out (returned null) when BSI scope is
        // not declared for the current tenant — keeps the surface clean.
        return array_filter($categories, static fn ($cat): bool => $cat !== null);
    }

    /**
     * Build the "BSI Policy-Wizard outputs" category. Returns null when BSI
     * scope is not declared for the tenant.
     *
     * @return array<string, mixed>|null
     */
    private function buildBsiPolicyWizardCategory(): ?array
    {
        if (!$this->isBsiInScope($this->tenantContext->getCurrentTenant())) {
            return null;
        }

        $checks = [];
        foreach ([
            BsiTopLevelLeitliniePresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BsiIsmsConceptPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BsiBaselineCoverageCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BsiSchutzbedarfMethodPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BsiTierConsistencyCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
            BsiKritisFlagDocumentedCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
        ] as $checkId => $meta) {
            $checks[$checkId] = [
                'name' => sprintf('compliance_check.%s.title', $checkId),
                'description' => sprintf('compliance_check.%s.description', $checkId),
                'translation_domain' => 'policy_wizard',
                'type' => 'policy_wizard',
                'check_id' => $checkId,
                'priority' => $meta['priority'],
                'route' => $meta['route'],
            ];
        }

        return [
            'name' => 'wizard.bsi.bsi_policies',
            'description' => 'wizard.bsi.bsi_policies_desc',
            'icon' => 'ui-flag',
            'weight' => 2,
            'article' => '200-1/200-2/200-3/200-4',
            'checks' => $checks,
        ];
    }

    /**
     * Detects whether BSI Grundschutz is in scope for the tenant. Signals:
     * - ComplianceFramework with `code='BSI_GRUNDSCHUTZ'` and `isActive=true`,
     *   OR
     * - Tenant policy setting `org.targets_bsi_certification` set to true.
     *
     * Returns false when neither signal is present (keeps the BSI category
     * out of generic admin previews).
     */
    private function isBsiInScope(?Tenant $tenant): bool
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'BSI_GRUNDSCHUTZ']);
        if ($framework instanceof ComplianceFramework && $framework->isActive() === true) {
            return true;
        }

        if ($tenant === null || $this->tenantPolicySettingRepository === null) {
            return false;
        }
        $setting = $this->tenantPolicySettingRepository->findOneByTenantAndKey(
            $tenant,
            'org.targets_bsi_certification',
        );
        if ($setting === null) {
            return false;
        }
        $value = $setting->getValue();
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    /**
     * BSI C5:2020 Cloud Compliance Categories
     *
     * Based on 17 chapters of the BSI Cloud Computing Compliance Controls Catalogue (C5:2020).
     */
    public function getBsiC5Categories(): array
    {
        return [
            'ois' => [
                'name' => 'wizard.bsi_c5.ois',
                'description' => 'wizard.bsi_c5.ois_desc',
                'maturity_baseline' => 'wizard.bsi_c5.ois_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.ois_enhanced',
                'icon' => 'nav-patch-check',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_ois' => [
                        'name' => 'wizard.check.bsi_c5_ois',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'sp' => [
                'name' => 'wizard.bsi_c5.sp',
                'description' => 'wizard.bsi_c5.sp_desc',
                'maturity_baseline' => 'wizard.bsi_c5.sp_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.sp_enhanced',
                'icon' => 'nav-shield',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_sp' => [
                        'name' => 'wizard.check.bsi_c5_sp',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'hr' => [
                'name' => 'wizard.bsi_c5.hr',
                'description' => 'wizard.bsi_c5.hr_desc',
                'maturity_baseline' => 'wizard.bsi_c5.hr_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.hr_enhanced',
                'icon' => 'nav-people',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_hr' => [
                        'name' => 'wizard.check.bsi_c5_hr',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'route' => 'app_training_index',
                    ],
                ],
            ],
            'am' => [
                'name' => 'wizard.bsi_c5.am',
                'description' => 'wizard.bsi_c5.am_desc',
                'maturity_baseline' => 'wizard.bsi_c5.am_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.am_enhanced',
                'icon' => 'asset-server',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_am' => [
                        'name' => 'wizard.check.bsi_c5_am',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'ps' => [
                'name' => 'wizard.bsi_c5.ps',
                'description' => 'wizard.bsi_c5.ps_desc',
                'maturity_baseline' => 'wizard.bsi_c5.ps_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.ps_enhanced',
                'icon' => 'nav-building',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_ps' => [
                        'name' => 'wizard.check.bsi_c5_ps',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_c5_ps_desc',
                        'route' => 'app_location_index',
                    ],
                ],
            ],
            'rb' => [
                'name' => 'wizard.bsi_c5.rb',
                'description' => 'wizard.bsi_c5.rb_desc',
                'maturity_baseline' => 'wizard.bsi_c5.rb_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.rb_enhanced',
                'icon' => 'util-refresh',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_rb' => [
                        'name' => 'wizard.check.bsi_c5_rb',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'route' => 'app_bcm_index',
                    ],
                ],
            ],
            'idm' => [
                'name' => 'wizard.bsi_c5.idm',
                'description' => 'wizard.bsi_c5.idm_desc',
                'maturity_baseline' => 'wizard.bsi_c5.idm_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.idm_enhanced',
                'icon' => 'ui-key',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_idm' => [
                        'name' => 'wizard.check.bsi_c5_idm',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'co' => [
                'name' => 'wizard.bsi_c5.co',
                'description' => 'wizard.bsi_c5.co_desc',
                'maturity_baseline' => 'wizard.bsi_c5.co_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.co_enhanced',
                'icon' => 'ui-lock',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_co' => [
                        'name' => 'wizard.check.bsi_c5_co',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'kos' => [
                'name' => 'wizard.bsi_c5.kos',
                'description' => 'wizard.bsi_c5.kos_desc',
                'maturity_baseline' => 'wizard.bsi_c5.kos_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.kos_enhanced',
                'icon' => 'nav-process',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_kos' => [
                        'name' => 'wizard.check.bsi_c5_kos',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'bcm' => [
                'name' => 'wizard.bsi_c5.bcm',
                'description' => 'wizard.bsi_c5.bcm_desc',
                'maturity_baseline' => 'wizard.bsi_c5.bcm_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.bcm_enhanced',
                'icon' => 'recovery',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_bcm' => [
                        'name' => 'wizard.check.bsi_c5_bcm',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'route' => 'app_bcm_index',
                    ],
                ],
            ],
            'im' => [
                'name' => 'wizard.bsi_c5.im',
                'description' => 'wizard.bsi_c5.im_desc',
                'maturity_baseline' => 'wizard.bsi_c5.im_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.im_enhanced',
                'icon' => 'status-critical',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_im' => [
                        'name' => 'wizard.check.bsi_c5_im',
                        'type' => 'incident_process',
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'com' => [
                'name' => 'wizard.bsi_c5.com',
                'description' => 'wizard.bsi_c5.com_desc',
                'maturity_baseline' => 'wizard.bsi_c5.com_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.com_enhanced',
                'icon' => 'nav-clipboard-check',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_com' => [
                        'name' => 'wizard.check.bsi_c5_com',
                        'type' => 'audit_status',
                        'module' => 'audits',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'inq' => [
                'name' => 'wizard.bsi_c5.inq',
                'description' => 'wizard.bsi_c5.inq_desc',
                'maturity_baseline' => 'wizard.bsi_c5.inq_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.inq_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_inq' => [
                        'name' => 'wizard.check.bsi_c5_inq',
                        'type' => 'supplier_assessment',
                        'module' => 'suppliers',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
            'pi' => [
                'name' => 'wizard.bsi_c5.pi',
                'description' => 'wizard.bsi_c5.pi_desc',
                'maturity_baseline' => 'wizard.bsi_c5.pi_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.pi_enhanced',
                'icon' => 'nav-tools',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_pi' => [
                        'name' => 'wizard.check.bsi_c5_pi',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'bei' => [
                'name' => 'wizard.bsi_c5.bei',
                'description' => 'wizard.bsi_c5.bei_desc',
                'maturity_baseline' => 'wizard.bsi_c5.bei_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.bei_enhanced',
                'icon' => 'nav-bar-chart',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_bei' => [
                        'name' => 'wizard.check.bsi_c5_bei',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_c5_bei_desc',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'pss' => [
                'name' => 'wizard.bsi_c5.pss',
                'description' => 'wizard.bsi_c5.pss_desc',
                'maturity_baseline' => 'wizard.bsi_c5.pss_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.pss_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_pss' => [
                        'name' => 'wizard.check.bsi_c5_pss',
                        'type' => 'supplier_assessment',
                        'module' => 'suppliers',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
            'bc' => [
                'name' => 'wizard.bsi_c5.bc',
                'description' => 'wizard.bsi_c5.bc_desc',
                'maturity_baseline' => 'wizard.bsi_c5.bc_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5.bc_enhanced',
                'icon' => 'util-refresh',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_bc' => [
                        'name' => 'wizard.check.bsi_c5_bc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_c5_bc_desc',
                        'route' => 'app_management_review_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * BSI C5:2026 Cloud Computing Compliance Criteria Catalogue Categories
     *
     * Reflects the 11 thematic clusters of the C5:2026 final release:
     * organisational, container, supply chain, post-quantum readiness,
     * confidential computing, AI/ML security, EUCS Substantial alignment,
     * enhanced client separation, NIS2 alignment, ISO 27001:2022 integration,
     * CSA CCM v4 alignment.
     */
    public function getBsiC52026Categories(): array
    {
        return [
            'organisation_personnel' => [
                'name' => 'wizard.bsi_c5_2026.organisation_personnel',
                'description' => 'wizard.bsi_c5_2026.organisation_personnel_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.organisation_personnel_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.organisation_personnel_enhanced',
                'icon' => 'nav-people',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_2026_organisation_personnel' => [
                        'name' => 'wizard.check.bsi_c5_2026_organisation_personnel',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'route' => 'app_training_index',
                    ],
                ],
            ],
            'container_management' => [
                'name' => 'wizard.bsi_c5_2026.container_management',
                'description' => 'wizard.bsi_c5_2026.container_management_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.container_management_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.container_management_enhanced',
                'icon' => 'nav-boxes',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_2026_container_management' => [
                        'name' => 'wizard.check.bsi_c5_2026_container_management',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'supply_chain_security' => [
                'name' => 'wizard.bsi_c5_2026.supply_chain_security',
                'description' => 'wizard.bsi_c5_2026.supply_chain_security_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.supply_chain_security_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.supply_chain_security_enhanced',
                'icon' => 'link',
                'weight' => 2.5,
                'checks' => [
                    'bsi_c5_2026_supply_chain_security' => [
                        'name' => 'wizard.check.bsi_c5_2026_supply_chain_security',
                        'type' => 'manual',
                        'priority' => 'high',
                        'description' => 'wizard.check.bsi_c5_2026_supply_chain_security_desc',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
            'post_quantum_cryptography' => [
                'name' => 'wizard.bsi_c5_2026.post_quantum_cryptography',
                'description' => 'wizard.bsi_c5_2026.post_quantum_cryptography_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.post_quantum_cryptography_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.post_quantum_cryptography_enhanced',
                'icon' => 'ui-key',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_2026_post_quantum_cryptography' => [
                        'name' => 'wizard.check.bsi_c5_2026_post_quantum_cryptography',
                        'type' => 'manual',
                        'priority' => 'medium',
                        'description' => 'wizard.check.bsi_c5_2026_post_quantum_cryptography_desc',
                        'route' => 'app_crypto_index',
                    ],
                ],
            ],
            'confidential_computing' => [
                'name' => 'wizard.bsi_c5_2026.confidential_computing',
                'description' => 'wizard.bsi_c5_2026.confidential_computing_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.confidential_computing_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.confidential_computing_enhanced',
                'icon' => 'nav-shield-lock',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_2026_confidential_computing' => [
                        'name' => 'wizard.check.bsi_c5_2026_confidential_computing',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'ai_ml_security' => [
                'name' => 'wizard.bsi_c5_2026.ai_ml_security',
                'description' => 'wizard.bsi_c5_2026.ai_ml_security_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.ai_ml_security_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.ai_ml_security_enhanced',
                'icon' => 'cpu',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_2026_ai_ml_security' => [
                        'name' => 'wizard.check.bsi_c5_2026_ai_ml_security',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'eucs_alignment' => [
                'name' => 'wizard.bsi_c5_2026.eucs_alignment',
                'description' => 'wizard.bsi_c5_2026.eucs_alignment_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.eucs_alignment_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.eucs_alignment_enhanced',
                'icon' => 'ui-flag',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_2026_eucs_alignment' => [
                        'name' => 'wizard.check.bsi_c5_2026_eucs_alignment',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'enhanced_client_separation' => [
                'name' => 'wizard.bsi_c5_2026.enhanced_client_separation',
                'description' => 'wizard.bsi_c5_2026.enhanced_client_separation_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.enhanced_client_separation_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.enhanced_client_separation_enhanced',
                'icon' => 'nav-process',
                'weight' => 1.5,
                'checks' => [
                    'bsi_c5_2026_enhanced_client_separation' => [
                        'name' => 'wizard.check.bsi_c5_2026_enhanced_client_separation',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'nis2_alignment' => [
                'name' => 'wizard.bsi_c5_2026.nis2_alignment',
                'description' => 'wizard.bsi_c5_2026.nis2_alignment_desc',
                'icon' => 'shield-check',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_2026_nis2_alignment' => [
                        'name' => 'wizard.check.bsi_c5_2026_nis2_alignment',
                        'type' => 'incident_coverage',
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'iso_27001_integration' => [
                'name' => 'wizard.bsi_c5_2026.iso_27001_integration',
                'description' => 'wizard.bsi_c5_2026.iso_27001_integration_desc',
                'icon' => 'ui-stars',
                'weight' => 2,
                'checks' => [
                    'bsi_c5_2026_iso_27001_integration' => [
                        'name' => 'wizard.check.bsi_c5_2026_iso_27001_integration',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'csa_ccm_alignment' => [
                'name' => 'wizard.bsi_c5_2026.csa_ccm_alignment',
                'description' => 'wizard.bsi_c5_2026.csa_ccm_alignment_desc',
                'maturity_baseline' => 'wizard.bsi_c5_2026.csa_ccm_alignment_baseline',
                'maturity_enhanced' => 'wizard.bsi_c5_2026.csa_ccm_alignment_enhanced',
                'icon' => 'nav-upload',
                'weight' => 1,
                'checks' => [
                    'bsi_c5_2026_csa_ccm_alignment' => [
                        'name' => 'wizard.check.bsi_c5_2026_csa_ccm_alignment',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
        ];
    }

    public function getBsiGrundschutzStandardCategories(): array
    {
        return [
            'initiation' => [
                'name' => 'wizard.bsi_grundschutz_standard.initiation',
                'description' => 'wizard.bsi_grundschutz_standard.initiation_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.initiation_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.initiation_enhanced',
                'icon' => 'play',
                'weight' => 1.5,
                'checks' => [
                    'bsi_gs_std_initiation' => [
                        'name' => 'wizard.check.bsi_gs_std_initiation',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_context_edit',
                    ],
                ],
            ],
            'security_concept' => [
                'name' => 'wizard.bsi_grundschutz_standard.security_concept',
                'description' => 'wizard.bsi_grundschutz_standard.security_concept_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.security_concept_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.security_concept_enhanced',
                'icon' => 'nav-file-earmark-text',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_std_concept' => [
                        'name' => 'wizard.check.bsi_gs_std_concept',
                        'type' => 'document_review',
                        'document_categories' => ['policy', 'concept'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'structure_analysis' => [
                'name' => 'wizard.bsi_grundschutz_standard.structure_analysis',
                'description' => 'wizard.bsi_grundschutz_standard.structure_analysis_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.structure_analysis_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.structure_analysis_enhanced',
                'icon' => 'nav-process',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_std_structure' => [
                        'name' => 'wizard.check.bsi_gs_std_structure',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'protection_needs' => [
                'name' => 'wizard.bsi_grundschutz_standard.protection_needs',
                'description' => 'wizard.bsi_grundschutz_standard.protection_needs_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.protection_needs_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.protection_needs_enhanced',
                'icon' => 'nav-shield-alert',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_std_protection_needs' => [
                        'name' => 'wizard.check.bsi_gs_std_protection_needs',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'modeling' => [
                'name' => 'wizard.bsi_grundschutz_standard.modeling',
                'description' => 'wizard.bsi_grundschutz_standard.modeling_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.modeling_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.modeling_enhanced',
                'icon' => 'nav-puzzle',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_std_modeling' => [
                        'name' => 'wizard.check.bsi_gs_std_modeling',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'grundschutz_check' => [
                'name' => 'wizard.bsi_grundschutz_standard.grundschutz_check',
                'description' => 'wizard.bsi_grundschutz_standard.grundschutz_check_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.grundschutz_check_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.grundschutz_check_enhanced',
                'icon' => 'status-ok',
                'weight' => 3,
                'checks' => [
                    'bsi_gs_std_check' => [
                        'name' => 'wizard.check.bsi_gs_std_check',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'risk_analysis' => [
                'name' => 'wizard.bsi_grundschutz_standard.risk_analysis',
                'description' => 'wizard.bsi_grundschutz_standard.risk_analysis_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.risk_analysis_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.risk_analysis_enhanced',
                'icon' => 'status-warning',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_std_risk_analysis' => [
                        'name' => 'wizard.check.bsi_gs_std_risk_analysis',
                        'type' => 'risk_coverage',
                        'route' => 'app_risk_index',
                    ],
                ],
            ],
            'realization' => [
                'name' => 'wizard.bsi_grundschutz_standard.realization',
                'description' => 'wizard.bsi_grundschutz_standard.realization_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_standard.realization_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_standard.realization_enhanced',
                'icon' => 'nav-list-check',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_std_realization' => [
                        'name' => 'wizard.check.bsi_gs_std_realization',
                        'type' => 'treatment_plan',
                        'route' => 'app_risk_treatment_plan_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * BSI IT-Grundschutz Kern-Absicherung categories (BSI 200-2 Kern-Absicherung)
     *
     * Focused on crown-jewel assets and accelerated protection assessment.
     * Smaller scope than Standard-Absicherung — only critical business processes.
     */
    public function getBsiGrundschutzKernCategories(): array
    {
        return [
            'crown_jewels' => [
                'name' => 'wizard.bsi_grundschutz_kern.crown_jewels',
                'description' => 'wizard.bsi_grundschutz_kern.crown_jewels_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_kern.crown_jewels_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_kern.crown_jewels_enhanced',
                'icon' => 'ui-star',
                'weight' => 3,
                'checks' => [
                    'bsi_gs_kern_crown_jewels' => [
                        'name' => 'wizard.check.bsi_gs_kern_crown_jewels',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'accelerated_protection_needs' => [
                'name' => 'wizard.bsi_grundschutz_kern.accelerated_protection_needs',
                'description' => 'wizard.bsi_grundschutz_kern.accelerated_protection_needs_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_kern.accelerated_protection_needs_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_kern.accelerated_protection_needs_enhanced',
                'icon' => 'status-warning',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_kern_protection_needs' => [
                        'name' => 'wizard.check.bsi_gs_kern_protection_needs',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'kern_modeling' => [
                'name' => 'wizard.bsi_grundschutz_kern.kern_modeling',
                'description' => 'wizard.bsi_grundschutz_kern.kern_modeling_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_kern.kern_modeling_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_kern.kern_modeling_enhanced',
                'icon' => 'nav-puzzle',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_kern_modeling' => [
                        'name' => 'wizard.check.bsi_gs_kern_modeling',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'kern_check' => [
                'name' => 'wizard.bsi_grundschutz_kern.kern_check',
                'description' => 'wizard.bsi_grundschutz_kern.kern_check_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_kern.kern_check_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_kern.kern_check_enhanced',
                'icon' => 'status-ok',
                'weight' => 3,
                'checks' => [
                    'bsi_gs_kern_check' => [
                        'name' => 'wizard.check.bsi_gs_kern_check',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'kern_realization' => [
                'name' => 'wizard.bsi_grundschutz_kern.kern_realization',
                'description' => 'wizard.bsi_grundschutz_kern.kern_realization_desc',
                'maturity_baseline' => 'wizard.bsi_grundschutz_kern.kern_realization_baseline',
                'maturity_enhanced' => 'wizard.bsi_grundschutz_kern.kern_realization_enhanced',
                'icon' => 'nav-list-check',
                'weight' => 2,
                'checks' => [
                    'bsi_gs_kern_realization' => [
                        'name' => 'wizard.check.bsi_gs_kern_realization',
                        'type' => 'treatment_plan',
                        'route' => 'app_risk_treatment_plan_index',
                    ],
                ],
            ],
        ];
    }
}
