<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\CategoryProvider;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmBiaMethodologyPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmCrisisManagementPlanPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmExerciseProgrammeActiveCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmManagementReviewBcmCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmRecoveryPlansPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmsScopeStatementPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bcm\BcmTopLevelPolicyPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Iso27701\Iso27701ClauseTagsAppliedCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Iso27701\Iso27701SchremsIIClauseInTransfersCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Iso27701\Iso27701VersionConfiguredCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\PolicyWizardTopicCatalogue;
use App\Service\TenantContext;
use App\Service\TenantSettingResolver\PolicySettingProvider;

/**
 * IsoFrameworkCategoryProvider
 *
 * Extracted from ComplianceWizardService (god-class decomposition).
 * Provides category definitions for ISO-family frameworks:
 * - ISO 27001:2022 (Information Security Management)
 * - ISO 22301:2019 (Business Continuity Management)
 * - ISO 27701:2019 (Privacy Information Management)
 * - ISO 27017:2015 (Cloud Security)
 * - ISO 27018:2019 (Cloud Privacy)
 * - ISO 42001:2023 (AI Management)
 */
final class IsoFrameworkCategoryProvider
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ?\App\Repository\TenantPolicySettingRepository $tenantPolicySettingRepository = null,
        private readonly ?PolicySettingProvider $policySettingProvider = null,
    ) {
    }

    /**
     * ISO 27001:2022 Categories
     *
     * Based on ISO/IEC 27001:2022 clauses:
     * - Clause 4: Context of the organization
     * - Clause 5: Leadership
     * - Clause 6: Planning
     * - Clause 7: Support
     * - Clause 8: Operation
     * - Clause 9: Performance evaluation
     * - Clause 10: Improvement
     * - Annex A: Information security controls (93 controls in 4 themes)
     */
    public function getIso27001Categories(): array
    {
        return [
            // Clause 4: Context of the organization
            'context' => [
                'name' => 'wizard.iso27001.context',
                'description' => 'wizard.iso27001.context_desc',
                'maturity_baseline' => 'wizard.iso27001.context_baseline',
                'maturity_enhanced' => 'wizard.iso27001.context_enhanced',
                'icon' => 'nav-process',
                'weight' => 1.5,
                'clause' => '4',
                'checks' => [
                    'understanding_organization' => [
                        'name' => 'wizard.check.iso_4_1_organization',
                        'description' => 'wizard.check.iso_4_1_organization_desc',
                        'type' => 'entity_presence',
                        'entity' => 'isms_context',
                        'priority' => 'critical',
                        'clause' => '4.1',
                        'route' => 'app_context_index',
                        'action' => 'wizard.action.define_context',
                    ],
                    'interested_parties' => [
                        'name' => 'wizard.check.iso_4_2_interested_parties',
                        'description' => 'wizard.check.iso_4_2_interested_parties_desc',
                        'type' => 'entity_presence',
                        'entity' => 'interested_party',
                        'priority' => 'critical',
                        'clause' => '4.2',
                        'route' => 'app_interested_party_index',
                        'action' => 'wizard.action.identify_parties',
                    ],
                    'isms_scope' => [
                        'name' => 'wizard.check.iso_4_3_scope',
                        'description' => 'wizard.check.iso_4_3_scope_desc',
                        'type' => 'entity_presence',
                        'entity' => 'isms_context',
                        'priority' => 'critical',
                        'clause' => '4.3',
                        'route' => 'app_context_index',
                        'action' => 'wizard.action.define_scope',
                    ],
                    'isms_established' => [
                        'name' => 'wizard.check.iso_4_4_isms',
                        'description' => 'wizard.check.iso_4_4_isms_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'clause' => '4.4',
                    ],
                ],
            ],

            // Clause 5: Leadership
            'leadership' => [
                'name' => 'wizard.iso27001.leadership',
                'description' => 'wizard.iso27001.leadership_desc',
                'maturity_baseline' => 'wizard.iso27001.leadership_baseline',
                'maturity_enhanced' => 'wizard.iso27001.leadership_enhanced',
                'icon' => 'nav-people',
                'weight' => 1.5,
                'clause' => '5',
                'checks' => [
                    'leadership_commitment' => [
                        'name' => 'wizard.check.iso_5_1_commitment',
                        'description' => 'wizard.check.iso_5_1_commitment_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'clause' => '5.1',
                    ],
                    'information_security_policy' => [
                        'name' => 'wizard.check.iso_5_2_policy',
                        'description' => 'wizard.check.iso_5_2_policy_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.1'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'clause' => '5.2',
                        'route' => 'app_soa_index',
                    ],
                    'roles_responsibilities' => [
                        'name' => 'wizard.check.iso_5_3_roles',
                        'description' => 'wizard.check.iso_5_3_roles_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.2', '5.3', '5.4'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'clause' => '5.3',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Clause 6: Planning
            'planning' => [
                'name' => 'wizard.iso27001.planning',
                'description' => 'wizard.iso27001.planning_desc',
                'maturity_baseline' => 'wizard.iso27001.planning_baseline',
                'maturity_enhanced' => 'wizard.iso27001.planning_enhanced',
                'icon' => 'nav-calendar-check',
                'weight' => 2,
                'clause' => '6',
                'checks' => [
                    'risk_assessment' => [
                        'name' => 'wizard.check.iso_6_1_1_risk_assessment',
                        'description' => 'wizard.check.iso_6_1_1_risk_assessment_desc',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'priority' => 'critical',
                        'clause' => '6.1.1/6.1.2',
                        'route' => 'app_risk_index',
                    ],
                    'risk_treatment' => [
                        'name' => 'wizard.check.iso_6_1_3_treatment',
                        'description' => 'wizard.check.iso_6_1_3_treatment_desc',
                        'type' => 'treatment_plan',
                        'module' => 'risks',
                        'priority' => 'critical',
                        'clause' => '6.1.3',
                        'route' => 'app_risk_treatment_plan_index',
                    ],
                    'statement_of_applicability' => [
                        'name' => 'wizard.check.iso_6_1_3_soa',
                        'description' => 'wizard.check.iso_6_1_3_soa_desc',
                        'type' => 'control_coverage',
                        'module' => 'controls',
                        'priority' => 'critical',
                        'clause' => '6.1.3 d)',
                        'route' => 'app_soa_index',
                    ],
                    'security_objectives' => [
                        'name' => 'wizard.check.iso_6_2_objectives',
                        'description' => 'wizard.check.iso_6_2_objectives_desc',
                        'type' => 'entity_presence',
                        'entity' => 'isms_objective',
                        'priority' => 'high',
                        'clause' => '6.2',
                        'route' => 'app_objective_index',
                        'action' => 'wizard.action.define_objectives',
                    ],
                    'planning_changes' => [
                        'name' => 'wizard.check.iso_6_3_changes',
                        'description' => 'wizard.check.iso_6_3_changes_desc',
                        'type' => 'manual',
                        'priority' => 'medium',
                        'clause' => '6.3',
                    ],
                ],
            ],

            // Clause 7: Support
            'support' => [
                'name' => 'wizard.iso27001.support',
                'description' => 'wizard.iso27001.support_desc',
                'maturity_baseline' => 'wizard.iso27001.support_baseline',
                'maturity_enhanced' => 'wizard.iso27001.support_enhanced',
                'icon' => 'nav-tools',
                'weight' => 1.5,
                'clause' => '7',
                'checks' => [
                    'resources' => [
                        'name' => 'wizard.check.iso_7_1_resources',
                        'description' => 'wizard.check.iso_7_1_resources_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'clause' => '7.1',
                    ],
                    'competence' => [
                        'name' => 'wizard.check.iso_7_2_competence',
                        'description' => 'wizard.check.iso_7_2_competence_desc',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'priority' => 'high',
                        'clause' => '7.2',
                        'route' => 'app_training_index',
                    ],
                    'awareness' => [
                        'name' => 'wizard.check.iso_7_3_awareness',
                        'description' => 'wizard.check.iso_7_3_awareness_desc',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'priority' => 'high',
                        'clause' => '7.3',
                        'route' => 'app_training_index',
                    ],
                    'communication' => [
                        'name' => 'wizard.check.iso_7_4_communication',
                        'description' => 'wizard.check.iso_7_4_communication_desc',
                        'type' => 'manual',
                        'priority' => 'medium',
                        'clause' => '7.4',
                    ],
                    'documented_information' => [
                        'name' => 'wizard.check.iso_7_5_documentation',
                        'description' => 'wizard.check.iso_7_5_documentation_desc',
                        'type' => 'document_review',
                        'priority' => 'high',
                        'clause' => '7.5',
                        'route' => 'app_document_index',
                    ],
                ],
            ],

            // Clause 8: Operation
            'operation' => [
                'name' => 'wizard.iso27001.operation',
                'description' => 'wizard.iso27001.operation_desc',
                'maturity_baseline' => 'wizard.iso27001.operation_baseline',
                'maturity_enhanced' => 'wizard.iso27001.operation_enhanced',
                'icon' => 'nav-gear',
                'weight' => 2,
                'clause' => '8',
                'checks' => [
                    'operational_planning' => [
                        'name' => 'wizard.check.iso_8_1_planning',
                        'description' => 'wizard.check.iso_8_1_planning_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'clause' => '8.1',
                    ],
                    'risk_assessment_execution' => [
                        'name' => 'wizard.check.iso_8_2_risk_exec',
                        'description' => 'wizard.check.iso_8_2_risk_exec_desc',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'priority' => 'critical',
                        'clause' => '8.2',
                        'route' => 'app_risk_index',
                    ],
                    'risk_treatment_execution' => [
                        'name' => 'wizard.check.iso_8_3_treatment_exec',
                        'description' => 'wizard.check.iso_8_3_treatment_exec_desc',
                        'type' => 'treatment_plan',
                        'module' => 'risks',
                        'priority' => 'critical',
                        'clause' => '8.3',
                        'route' => 'app_risk_treatment_plan_index',
                    ],
                ],
            ],

            // Clause 9: Performance evaluation
            'performance' => [
                'name' => 'wizard.iso27001.performance',
                'description' => 'wizard.iso27001.performance_desc',
                'maturity_baseline' => 'wizard.iso27001.performance_baseline',
                'maturity_enhanced' => 'wizard.iso27001.performance_enhanced',
                'icon' => 'nav-bar-chart',
                'weight' => 1.5,
                'clause' => '9',
                'checks' => [
                    'monitoring_measurement' => [
                        'name' => 'wizard.check.iso_9_1_monitoring',
                        'description' => 'wizard.check.iso_9_1_monitoring_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'clause' => '9.1',
                    ],
                    'internal_audit' => [
                        'name' => 'wizard.check.iso_9_2_audit',
                        'description' => 'wizard.check.iso_9_2_audit_desc',
                        'type' => 'audit_status',
                        'module' => 'audits',
                        'priority' => 'critical',
                        'clause' => '9.2',
                        'route' => 'app_audit_index',
                    ],
                    'management_review' => [
                        'name' => 'wizard.check.iso_9_3_review',
                        'description' => 'wizard.check.iso_9_3_review_desc',
                        'type' => 'entity_presence',
                        'entity' => 'management_review',
                        'priority' => 'critical',
                        'clause' => '9.3',
                        'route' => 'app_management_review_index',
                        'action' => 'wizard.action.management_review',
                    ],
                ],
            ],

            // Clause 10: Improvement
            'improvement' => [
                'name' => 'wizard.iso27001.improvement',
                'description' => 'wizard.iso27001.improvement_desc',
                'maturity_baseline' => 'wizard.iso27001.improvement_baseline',
                'maturity_enhanced' => 'wizard.iso27001.improvement_enhanced',
                'icon' => 'util-arrow-up',
                'weight' => 1,
                'clause' => '10',
                'checks' => [
                    'nonconformity' => [
                        'name' => 'wizard.check.iso_10_1_nonconformity',
                        'description' => 'wizard.check.iso_10_1_nonconformity_desc',
                        'type' => 'audit_status',
                        'module' => 'audits',
                        'priority' => 'high',
                        'clause' => '10.1',
                        'route' => 'app_audit_index',
                    ],
                    'continual_improvement' => [
                        'name' => 'wizard.check.iso_10_2_improvement',
                        'description' => 'wizard.check.iso_10_2_improvement_desc',
                        'type' => 'manual',
                        'priority' => 'medium',
                        'clause' => '10.2',
                    ],
                ],
            ],

            // Annex A: Controls (4 Themes)
            'annex_a_organizational' => [
                'name' => 'wizard.iso27001.annex_a_organizational',
                'description' => 'wizard.iso27001.annex_a_organizational_desc',
                'maturity_baseline' => 'wizard.iso27001.annex_a_organizational_baseline',
                'maturity_enhanced' => 'wizard.iso27001.annex_a_organizational_enhanced',
                'icon' => 'nav-building',
                'weight' => 2,
                'clause' => 'A.5',
                'checks' => [
                    'organizational_controls' => [
                        'name' => 'wizard.check.iso_annex_a5',
                        'description' => 'wizard.check.iso_annex_a5_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.1', '5.2', '5.3', '5.4', '5.5', '5.6', '5.7', '5.8', '5.9', '5.10',
                            '5.11', '5.12', '5.13', '5.14', '5.15', '5.16', '5.17', '5.18', '5.19', '5.20',
                            '5.21', '5.22', '5.23', '5.24', '5.25', '5.26', '5.27', '5.28', '5.29', '5.30',
                            '5.31', '5.32', '5.33', '5.34', '5.35', '5.36', '5.37'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'annex_a_people' => [
                'name' => 'wizard.iso27001.annex_a_people',
                'description' => 'wizard.iso27001.annex_a_people_desc',
                'maturity_baseline' => 'wizard.iso27001.annex_a_people_baseline',
                'maturity_enhanced' => 'wizard.iso27001.annex_a_people_enhanced',
                'icon' => 'nav-people',
                'weight' => 1.5,
                'clause' => 'A.6',
                'checks' => [
                    'people_controls' => [
                        'name' => 'wizard.check.iso_annex_a6',
                        'description' => 'wizard.check.iso_annex_a6_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['6.1', '6.2', '6.3', '6.4', '6.5', '6.6', '6.7', '6.8'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'annex_a_physical' => [
                'name' => 'wizard.iso27001.annex_a_physical',
                'description' => 'wizard.iso27001.annex_a_physical_desc',
                'maturity_baseline' => 'wizard.iso27001.annex_a_physical_baseline',
                'maturity_enhanced' => 'wizard.iso27001.annex_a_physical_enhanced',
                'icon' => 'nav-building',
                'weight' => 1.5,
                'clause' => 'A.7',
                'checks' => [
                    'physical_controls' => [
                        'name' => 'wizard.check.iso_annex_a7',
                        'description' => 'wizard.check.iso_annex_a7_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['7.1', '7.2', '7.3', '7.4', '7.5', '7.6', '7.7', '7.8', '7.9', '7.10',
                            '7.11', '7.12', '7.13', '7.14'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'annex_a_technological' => [
                'name' => 'wizard.iso27001.annex_a_technological',
                'description' => 'wizard.iso27001.annex_a_technological_desc',
                'maturity_baseline' => 'wizard.iso27001.annex_a_technological_baseline',
                'maturity_enhanced' => 'wizard.iso27001.annex_a_technological_enhanced',
                'icon' => 'cpu',
                'weight' => 2,
                'clause' => 'A.8',
                'checks' => [
                    'technological_controls' => [
                        'name' => 'wizard.check.iso_annex_a8',
                        'description' => 'wizard.check.iso_annex_a8_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.1', '8.2', '8.3', '8.4', '8.5', '8.6', '8.7', '8.8', '8.9', '8.10',
                            '8.11', '8.12', '8.13', '8.14', '8.15', '8.16', '8.17', '8.18', '8.19', '8.20',
                            '8.21', '8.22', '8.23', '8.24', '8.25', '8.26', '8.27', '8.28', '8.29', '8.30',
                            '8.31', '8.32', '8.33', '8.34'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Asset Management (supports Annex A)
            'asset_management' => [
                'name' => 'wizard.iso27001.asset_management',
                'description' => 'wizard.iso27001.asset_management_desc',
                'maturity_baseline' => 'wizard.iso27001.asset_management_baseline',
                'maturity_enhanced' => 'wizard.iso27001.asset_management_enhanced',
                'icon' => 'asset-database',
                'weight' => 1.5,
                'checks' => [
                    'asset_inventory' => [
                        'name' => 'wizard.check.asset_inventory',
                        'description' => 'wizard.check.asset_inventory_desc',
                        'type' => 'asset_coverage',
                        'module' => 'assets',
                        'priority' => 'critical',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],

            // Incident Management (supports A.5.24-A.5.28)
            'incident_management' => [
                'name' => 'wizard.iso27001.incident_management',
                'description' => 'wizard.iso27001.incident_management_desc',
                'maturity_baseline' => 'wizard.iso27001.incident_management_baseline',
                'maturity_enhanced' => 'wizard.iso27001.incident_management_enhanced',
                'icon' => 'status-critical',
                'weight' => 1.5,
                'checks' => [
                    'incident_process' => [
                        'name' => 'wizard.check.incident_management',
                        'description' => 'wizard.check.incident_management_desc',
                        'type' => 'incident_process',
                        'sla_hours' => 72,
                        'module' => 'incidents',
                        'priority' => 'high',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],

            // Business Continuity (supports A.5.29-A.5.30)
            'business_continuity' => [
                'name' => 'wizard.iso27001.business_continuity',
                'description' => 'wizard.iso27001.business_continuity_desc',
                'maturity_baseline' => 'wizard.iso27001.business_continuity_baseline',
                'maturity_enhanced' => 'wizard.iso27001.business_continuity_enhanced',
                'icon' => 'util-refresh',
                'weight' => 1.5,
                'checks' => [
                    'bcm_coverage' => [
                        'name' => 'wizard.check.bcm_coverage',
                        'description' => 'wizard.check.bcm_coverage_desc',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'priority' => 'high',
                        'route' => 'app_bcm_index',
                    ],
                ],
            ],

            // Policy-Wizard outputs — Top-Level (ISO 27001 Cl. 5.2)
            'policies_top_level' => [
                'name' => 'wizard.iso27001.policies_top_level',
                'description' => 'wizard.iso27001.policies_top_level_desc',
                'icon' => 'nav-file-earmark-text',
                'weight' => 1.5,
                'clause' => '5.2',
                'checks' => [
                    'policy_top_level_present' => [
                        'name' => 'compliance_check.policy_top_level_present.title',
                        'description' => 'compliance_check.policy_top_level_present.description',
                        'translation_domain' => 'policy_wizard',
                        'type' => 'policy_wizard',
                        'check_id' => 'policy_top_level_present',
                        'priority' => 'critical',
                        'clause' => '5.2',
                        'route' => 'app_policy_wizard_index',
                    ],
                ],
            ],

            // Policy-Wizard outputs — 24 ISO 27002 topic policies + 4 cross-cutting
            'policies_topic_coverage' => $this->buildPolicyWizardTopicCategory(),
        ];
    }

    /**
     * Build the "Policies / Topic Coverage" category for the ISO 27001 wizard.
     *
     * Aggregates the 24 ISO 27002:2022 topic policies (one row per topic from
     * {@see PolicyWizardTopicCatalogue::ISO27001_TOPICS}) plus the 4
     * cross-cutting Policy-Wizard checks (approval-chain, acknowledgement,
     * review-cadence, tailoring-fields). Each row is a `policy_wizard`
     * type and resolves through {@see dispatchPolicyWizardCheck()} into the
     * registry.
     *
     * @return array<string, mixed>
     */
    private function buildPolicyWizardTopicCategory(): array
    {
        $checks = [];

        foreach (PolicyWizardTopicCatalogue::iso27001Topics() as $topic) {
            $checkId = sprintf('policy_topic_%s_present', $topic);
            $checks[$checkId] = [
                'name' => sprintf('compliance_check.%s.title', $checkId),
                'description' => sprintf('compliance_check.%s.description', $checkId),
                'translation_domain' => 'policy_wizard',
                'type' => 'policy_wizard',
                'check_id' => $checkId,
                'priority' => 'high',
                'route' => 'app_policy_wizard_index',
            ];
        }

        // Cross-cutting Policy-Wizard checks (approval-chain, acknowledgement,
        // review-cadence, tailoring-fields) — apply across all generated
        // policies, not per-topic.
        foreach ([
            'policy_approval_chain_completed' => 'critical',
            'policy_acknowledgement_coverage' => 'high',
            'policy_review_cadence' => 'high',
            'policy_tailoring_fields' => 'medium',
        ] as $checkId => $priority) {
            $checks[$checkId] = [
                'name' => sprintf('compliance_check.%s.title', $checkId),
                'description' => sprintf('compliance_check.%s.description', $checkId),
                'translation_domain' => 'policy_wizard',
                'type' => 'policy_wizard',
                'check_id' => $checkId,
                'priority' => $priority,
                'route' => 'app_policy_wizard_index',
            ];
        }

        return [
            'name' => 'wizard.iso27001.policies_topic_coverage',
            'description' => 'wizard.iso27001.policies_topic_coverage_desc',
            'icon' => 'documents',
            'weight' => 2,
            'clause' => 'A.5/A.6/A.7/A.8',
            'checks' => $checks,
        ];
    }

    /**
     * Categories for ISO 22301:2019 readiness — Business Continuity Management.
     *
     * Maps to the 7 management-system clauses (4-10) and reuses the existing
     * check-types (bcm_coverage, document_review, audit_status, etc.) so this
     * wizard can ship without new dependencies.
     */
    public function getIso22301Categories(): array
    {
        $categories = [
            'context' => [
                'name' => 'wizard.iso22301.context',
                'description' => 'wizard.iso22301.context_desc',
                'maturity_baseline' => 'wizard.iso22301.context_baseline',
                'maturity_enhanced' => 'wizard.iso22301.context_enhanced',
                'icon' => 'ui-globe',
                'weight' => 1.5,
                'checks' => [
                    'scope_definition' => [
                        'name' => 'wizard.check.iso22301_scope',
                        'type' => 'entity_presence',
                        'entity' => 'isms_context',
                        'priority' => 'high',
                        'description' => 'wizard.check.iso22301_scope_desc',
                        'route' => 'app_context_edit',
                    ],
                ],
            ],
            'leadership' => [
                'name' => 'wizard.iso22301.leadership',
                'description' => 'wizard.iso22301.leadership_desc',
                'maturity_baseline' => 'wizard.iso22301.leadership_baseline',
                'maturity_enhanced' => 'wizard.iso22301.leadership_enhanced',
                'icon' => 'nav-people',
                'weight' => 1.5,
                'checks' => [
                    'bcm_policy' => [
                        'name' => 'wizard.check.iso22301_policy',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'planning' => [
                'name' => 'wizard.iso22301.planning',
                'description' => 'wizard.iso22301.planning_desc',
                'maturity_baseline' => 'wizard.iso22301.planning_baseline',
                'maturity_enhanced' => 'wizard.iso22301.planning_enhanced',
                'icon' => 'nav-clipboard-data',
                'weight' => 2,
                'checks' => [
                    'bia_risks' => [
                        'name' => 'wizard.check.iso22301_bia',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'route' => 'app_risk_index',
                    ],
                ],
            ],
            'support' => [
                'name' => 'wizard.iso22301.support',
                'description' => 'wizard.iso22301.support_desc',
                'maturity_baseline' => 'wizard.iso22301.support_baseline',
                'maturity_enhanced' => 'wizard.iso22301.support_enhanced',
                'icon' => 'nav-people',
                'weight' => 1,
                'checks' => [
                    'training' => [
                        'name' => 'wizard.check.iso22301_training',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'route' => 'app_training_index',
                    ],
                ],
            ],
            'operation' => [
                'name' => 'wizard.iso22301.operation',
                'description' => 'wizard.iso22301.operation_desc',
                'maturity_baseline' => 'wizard.iso22301.operation_baseline',
                'maturity_enhanced' => 'wizard.iso22301.operation_enhanced',
                'icon' => 'nav-gear',
                'weight' => 3,
                'checks' => [
                    'bc_plans' => [
                        'name' => 'wizard.check.iso22301_bcplans',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'route' => 'app_bcm_index',
                    ],
                ],
            ],
            'evaluation' => [
                'name' => 'wizard.iso22301.evaluation',
                'description' => 'wizard.iso22301.evaluation_desc',
                'maturity_baseline' => 'wizard.iso22301.evaluation_baseline',
                'maturity_enhanced' => 'wizard.iso22301.evaluation_enhanced',
                'icon' => 'nav-bar-chart',
                'weight' => 2,
                'checks' => [
                    'bcm_audit' => [
                        'name' => 'wizard.check.iso22301_audit',
                        'type' => 'audit_status',
                        'module' => 'audits',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'improvement' => [
                'name' => 'wizard.iso22301.improvement',
                'description' => 'wizard.iso22301.improvement_desc',
                'maturity_baseline' => 'wizard.iso22301.improvement_baseline',
                'maturity_enhanced' => 'wizard.iso22301.improvement_enhanced',
                'icon' => 'util-arrow-up',
                'weight' => 1,
                'checks' => [
                    'treatment' => [
                        'name' => 'wizard.check.iso22301_treatment',
                        'type' => 'treatment_plan',
                        'module' => 'risks',
                        'route' => 'app_risk_treatment_plan_index',
                    ],
                ],
            ],
            // Policy-Wizard outputs — BCM-specific policies (top-level, scope,
            // BIA methodology, exercise programme, crisis plan, recovery plans,
            // management review). Gated on BCM scope being active
            // (`bcm.enabled` tenant setting OR ComplianceFramework code
            // 'ISO_22301' active). Returns null when out-of-scope.
            'bcm_policies' => $this->buildBcmPolicyWizardCategory(),
        ];

        return array_filter($categories, static fn ($cat): bool => $cat !== null);
    }

    /**
     * Build the "BCM Policy-Wizard outputs" category. Returns null when BCM
     * scope is not declared for the tenant.
     *
     * @return array<string, mixed>|null
     */
    private function buildBcmPolicyWizardCategory(): ?array
    {
        if (!$this->isBcmInScope($this->tenantContext->getCurrentTenant())) {
            return null;
        }

        $checks = [];
        foreach ([
            BcmTopLevelPolicyPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BcmsScopeStatementPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BcmBiaMethodologyPresentCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
            BcmExerciseProgrammeActiveCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_bc_exercise_index'],
            BcmCrisisManagementPlanPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BcmRecoveryPlansPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            BcmManagementReviewBcmCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
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
            'name' => 'wizard.bcm.bcm_policies',
            'description' => 'wizard.bcm.bcm_policies_desc',
            'icon' => 'recovery',
            'weight' => 2,
            'article' => 'ISO 22301 Cl. 5.2/4.3/8.2.2/8.4.4/8.4.5/8.6/9.3',
            'checks' => $checks,
        ];
    }

    /**
     * Detects whether BCM is in scope for the tenant. Signals:
     * - ComplianceFramework with `code='ISO_22301'` and `isActive=true`, OR
     * - Tenant policy setting `bcm.enabled` set to true.
     */
    private function isBcmInScope(?Tenant $tenant): bool
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'ISO-22301']);
        if ($framework instanceof ComplianceFramework && $framework->isActive() === true) {
            return true;
        }

        if ($tenant === null || $this->tenantPolicySettingRepository === null) {
            return false;
        }
        $setting = $this->tenantPolicySettingRepository->findOneByTenantAndKey(
            $tenant,
            'bcm.enabled',
        );
        if ($setting === null) {
            return false;
        }
        $value = $setting->getValue();
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    /**
     * Categories for ISO 27701:2019 readiness — Privacy Information Management
     * System. Maps to the PIMS-specific extensions on top of ISO 27001 — focuses
     * on the eight blocks the standard's Annex A/B require organisations to
     * demonstrate.
     */
    public function getIso27701Categories(): array
    {
        $categories = [
            'pims_context' => [
                'name' => 'wizard.iso27701.pims_context',
                'description' => 'wizard.iso27701.pims_context_desc',
                'maturity_baseline' => 'wizard.iso27701.pims_context_baseline',
                'maturity_enhanced' => 'wizard.iso27701.pims_context_enhanced',
                'icon' => 'nav-process',
                'weight' => 1.5,
                'checks' => [
                    'controller_processor_role' => [
                        'name' => 'wizard.check.iso27701_role',
                        'type' => 'entity_presence',
                        'entity' => 'isms_context',
                        'priority' => 'high',
                        'description' => 'wizard.check.iso27701_role_desc',
                        'route' => 'app_context_edit',
                    ],
                ],
            ],
            'privacy_policy' => [
                'name' => 'wizard.iso27701.privacy_policy',
                'description' => 'wizard.iso27701.privacy_policy_desc',
                'maturity_baseline' => 'wizard.iso27701.privacy_policy_baseline',
                'maturity_enhanced' => 'wizard.iso27701.privacy_policy_enhanced',
                'icon' => 'nav-file-check',
                'weight' => 1.5,
                'checks' => [
                    'policy_doc' => [
                        'name' => 'wizard.check.iso27701_policy',
                        'type' => 'document_review',
                        'document_categories' => ['policy', 'privacy'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'data_subject_rights' => [
                'name' => 'wizard.iso27701.data_subject_rights',
                'description' => 'wizard.iso27701.data_subject_rights_desc',
                'maturity_baseline' => 'wizard.iso27701.data_subject_rights_baseline',
                'maturity_enhanced' => 'wizard.iso27701.data_subject_rights_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'dsr_pipeline' => [
                        'name' => 'wizard.check.iso27701_dsr',
                        'type' => 'dsr_coverage',
                        'module' => 'privacy',
                        'route' => 'app_data_subject_request_index',
                    ],
                ],
            ],
            'privacy_risk' => [
                'name' => 'wizard.iso27701.privacy_risk',
                'description' => 'wizard.iso27701.privacy_risk_desc',
                'maturity_baseline' => 'wizard.iso27701.privacy_risk_baseline',
                'maturity_enhanced' => 'wizard.iso27701.privacy_risk_enhanced',
                'icon' => 'status-critical',
                'weight' => 3,
                'checks' => [
                    'dpia' => [
                        'name' => 'wizard.check.iso27701_dpia',
                        'type' => 'dpia_coverage',
                        'module' => 'privacy',
                        'route' => 'app_processing_activity_index',
                    ],
                ],
            ],
            'records_of_processing' => [
                'name' => 'wizard.iso27701.records_of_processing',
                'description' => 'wizard.iso27701.records_of_processing_desc',
                'maturity_baseline' => 'wizard.iso27701.records_of_processing_baseline',
                'maturity_enhanced' => 'wizard.iso27701.records_of_processing_enhanced',
                'icon' => 'nav-list-check',
                'weight' => 2,
                'checks' => [
                    'processing_records' => [
                        'name' => 'wizard.check.iso27701_records',
                        'type' => 'entity_presence',
                        'entity' => 'processing_activity',
                        'priority' => 'critical',
                        'route' => 'app_processing_activity_index',
                    ],
                ],
            ],
            'breach_notification' => [
                'name' => 'wizard.iso27701.breach_notification',
                'description' => 'wizard.iso27701.breach_notification_desc',
                'maturity_baseline' => 'wizard.iso27701.breach_notification_baseline',
                'maturity_enhanced' => 'wizard.iso27701.breach_notification_enhanced',
                'icon' => 'status-warning',
                'weight' => 1.5,
                'checks' => [
                    'breach_process' => [
                        'name' => 'wizard.check.iso27701_breach',
                        'type' => 'incident_process',
                        'sla_hours' => 72,
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'privacy_by_design' => [
                'name' => 'wizard.iso27701.privacy_by_design',
                'description' => 'wizard.iso27701.privacy_by_design_desc',
                'maturity_baseline' => 'wizard.iso27701.privacy_by_design_baseline',
                'maturity_enhanced' => 'wizard.iso27701.privacy_by_design_enhanced',
                'icon' => 'nav-tools',
                'weight' => 1.5,
                'checks' => [
                    'consent_pipeline' => [
                        'name' => 'wizard.check.iso27701_consent',
                        'type' => 'consent_coverage',
                        'module' => 'privacy',
                        'route' => 'app_consent_index',
                    ],
                ],
            ],
            'third_party_processors' => [
                'name' => 'wizard.iso27701.third_party_processors',
                'description' => 'wizard.iso27701.third_party_processors_desc',
                'maturity_baseline' => 'wizard.iso27701.third_party_processors_baseline',
                'maturity_enhanced' => 'wizard.iso27701.third_party_processors_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'supplier_assessment' => [
                        'name' => 'wizard.check.iso27701_suppliers',
                        'type' => 'supplier_assessment',
                        'module' => 'suppliers',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],

            // Policy-Wizard outputs — ISO 27701 PIMS-specific checks (version
            // declaration, clause-level mapping tags, Schrems II + supplementary
            // measures wording on transfers). Gated on `iso27701.enabled` tenant
            // policy setting being true. Returns null when out-of-scope.
            'iso27701_pims' => $this->buildIso27701PolicyWizardCategory(),
        ];

        return array_filter($categories, static fn ($cat): bool => $cat !== null);
    }

    /**
     * Build the "ISO 27701 PIMS Policy-Wizard outputs" category. Returns null
     * when the PIMS addon is not opted in for the tenant.
     *
     * @return array<string, mixed>|null
     */
    private function buildIso27701PolicyWizardCategory(): ?array
    {
        if (!$this->isIso27701InScope($this->tenantContext->getCurrentTenant())) {
            return null;
        }

        $checks = [];
        foreach ([
            Iso27701VersionConfiguredCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
            Iso27701ClauseTagsAppliedCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_document_index'],
            Iso27701SchremsIIClauseInTransfersCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
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
            'name' => 'wizard.iso27701.iso27701_pims',
            'description' => 'wizard.iso27701.iso27701_pims_desc',
            'icon' => 'shield-check',
            'weight' => 1.5,
            'article' => 'ISO 27701:2025 Cl. 5.1/7.2.8/7.5 + GDPR Art. 44-49',
            'checks' => $checks,
        ];
    }

    /**
     * Detects whether the ISO 27701 PIMS addon is opted in for the tenant.
     * Signal: `iso27701.enabled` tenant policy setting set to true (resolved
     * via {@see PolicySettingProvider}). When the provider is not wired in
     * (legacy DI compositions in tests) the category stays hidden.
     */
    private function isIso27701InScope(?Tenant $tenant): bool
    {
        if ($this->policySettingProvider === null) {
            return false;
        }
        return $this->policySettingProvider->isIso27701Enabled($tenant);
    }

    /**
     * Categories for ISO/IEC 27017:2015 readiness — Cloud Security.
     * Covers the cloud-specific control extensions to ISO 27001 Annex A
     * for both cloud service customers and cloud service providers.
     */
    public function getIso27017Categories(): array
    {
        return [
            'shared_responsibility' => [
                'name' => 'wizard.iso27017.shared_responsibility',
                'description' => 'wizard.iso27017.shared_responsibility_desc',
                'maturity_baseline' => 'wizard.iso27017.shared_responsibility_baseline',
                'maturity_enhanced' => 'wizard.iso27017.shared_responsibility_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'iso27017_shared_responsibility' => [
                        'name' => 'wizard.check.iso27017_shared_responsibility',
                        'type' => 'entity_presence',
                        'entity' => 'supplier',
                        'priority' => 'high',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
            'cloud_asset_inventory' => [
                'name' => 'wizard.iso27017.cloud_asset_inventory',
                'description' => 'wizard.iso27017.cloud_asset_inventory_desc',
                'maturity_baseline' => 'wizard.iso27017.cloud_asset_inventory_baseline',
                'maturity_enhanced' => 'wizard.iso27017.cloud_asset_inventory_enhanced',
                'icon' => 'nav-download',
                'weight' => 2,
                'checks' => [
                    'iso27017_cloud_assets' => [
                        'name' => 'wizard.check.iso27017_cloud_assets',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'customer_separation' => [
                'name' => 'wizard.iso27017.customer_separation',
                'description' => 'wizard.iso27017.customer_separation_desc',
                'maturity_baseline' => 'wizard.iso27017.customer_separation_baseline',
                'maturity_enhanced' => 'wizard.iso27017.customer_separation_enhanced',
                'icon' => 'nav-process',
                'weight' => 1.5,
                'checks' => [
                    'iso27017_separation' => [
                        'name' => 'wizard.check.iso27017_separation',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'virtual_machine_hardening' => [
                'name' => 'wizard.iso27017.vm_hardening',
                'description' => 'wizard.iso27017.vm_hardening_desc',
                'icon' => 'asset-endpoint',
                'weight' => 1.5,
                'checks' => [
                    'iso27017_vm_hardening' => [
                        'name' => 'wizard.check.iso27017_vm_hardening',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'admin_access' => [
                'name' => 'wizard.iso27017.admin_access',
                'description' => 'wizard.iso27017.admin_access_desc',
                'maturity_baseline' => 'wizard.iso27017.admin_access_baseline',
                'maturity_enhanced' => 'wizard.iso27017.admin_access_enhanced',
                'icon' => 'ui-key',
                'weight' => 2,
                'checks' => [
                    'iso27017_admin_access' => [
                        'name' => 'wizard.check.iso27017_admin_access',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'cloud_supplier_governance' => [
                'name' => 'wizard.iso27017.cloud_supplier_governance',
                'description' => 'wizard.iso27017.cloud_supplier_governance_desc',
                'maturity_baseline' => 'wizard.iso27017.cloud_supplier_governance_baseline',
                'maturity_enhanced' => 'wizard.iso27017.cloud_supplier_governance_enhanced',
                'icon' => 'nav-building-check',
                'weight' => 2,
                'checks' => [
                    'iso27017_cloud_suppliers' => [
                        'name' => 'wizard.check.iso27017_cloud_suppliers',
                        'type' => 'supplier_assessment',
                        'module' => 'suppliers',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
            'monitoring_and_logging' => [
                'name' => 'wizard.iso27017.monitoring_logging',
                'description' => 'wizard.iso27017.monitoring_logging_desc',
                'icon' => 'nav-activity',
                'weight' => 1.5,
                'checks' => [
                    'iso27017_monitoring' => [
                        'name' => 'wizard.check.iso27017_monitoring',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * Categories for ISO/IEC 27018:2019 readiness — PII in Cloud.
     * Covers PII processor obligations on top of ISO 27001 + 27002 for
     * organisations acting as public cloud PII processors.
     */
    public function getIso27018Categories(): array
    {
        return [
            'pii_consent_processor' => [
                'name' => 'wizard.iso27018.pii_consent_processor',
                'description' => 'wizard.iso27018.pii_consent_processor_desc',
                'maturity_baseline' => 'wizard.iso27018.pii_consent_processor_baseline',
                'maturity_enhanced' => 'wizard.iso27018.pii_consent_processor_enhanced',
                'icon' => 'nav-file-check',
                'weight' => 2,
                'checks' => [
                    'iso27018_consent' => [
                        'name' => 'wizard.check.iso27018_consent',
                        'type' => 'consent_coverage',
                        'module' => 'privacy',
                        'route' => 'app_consent_index',
                    ],
                ],
            ],
            'pii_purpose_limitation' => [
                'name' => 'wizard.iso27018.purpose_limitation',
                'description' => 'wizard.iso27018.purpose_limitation_desc',
                'icon' => 'nav-bullseye',
                'weight' => 2,
                'checks' => [
                    'iso27018_purpose' => [
                        'name' => 'wizard.check.iso27018_purpose',
                        'type' => 'entity_presence',
                        'entity' => 'processing_activity',
                        'priority' => 'high',
                        'route' => 'app_processing_activity_index',
                    ],
                ],
            ],
            'pii_retention_disposal' => [
                'name' => 'wizard.iso27018.retention_disposal',
                'description' => 'wizard.iso27018.retention_disposal_desc',
                'icon' => 'ui-delete',
                'weight' => 2,
                'checks' => [
                    'iso27018_retention' => [
                        'name' => 'wizard.check.iso27018_retention',
                        'type' => 'entity_presence',
                        'entity' => 'processing_activity',
                        'priority' => 'high',
                        'route' => 'app_processing_activity_index',
                    ],
                ],
            ],
            'pii_access_transparency' => [
                'name' => 'wizard.iso27018.access_transparency',
                'description' => 'wizard.iso27018.access_transparency_desc',
                'icon' => 'ui-eye',
                'weight' => 1.5,
                'checks' => [
                    'iso27018_dsr' => [
                        'name' => 'wizard.check.iso27018_dsr',
                        'type' => 'dsr_coverage',
                        'module' => 'privacy',
                        'route' => 'app_data_subject_request_index',
                    ],
                ],
            ],
            'pii_breach_notification' => [
                'name' => 'wizard.iso27018.breach_notification',
                'description' => 'wizard.iso27018.breach_notification_desc',
                'icon' => 'bell',
                'weight' => 2,
                'checks' => [
                    'iso27018_breach' => [
                        'name' => 'wizard.check.iso27018_breach',
                        'type' => 'incident_process',
                        'sla_hours' => 72,
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'pii_subprocessor_governance' => [
                'name' => 'wizard.iso27018.subprocessor_governance',
                'description' => 'wizard.iso27018.subprocessor_governance_desc',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'iso27018_subprocessors' => [
                        'name' => 'wizard.check.iso27018_subprocessors',
                        'type' => 'supplier_assessment',
                        'module' => 'suppliers',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * Categories for ISO/IEC 42001:2023 readiness — AI Management System.
     * Covers management-system clauses 4-10 plus Annex A AI-specific controls
     * for organisations developing, providing or using AI systems.
     */
    public function getIso42001Categories(): array
    {
        return [
            'ai_context' => [
                'name' => 'wizard.iso42001.ai_context',
                'description' => 'wizard.iso42001.ai_context_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_context_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_context_enhanced',
                'icon' => 'ui-globe',
                'weight' => 1.5,
                'checks' => [
                    'iso42001_context' => [
                        'name' => 'wizard.check.iso42001_context',
                        'type' => 'entity_presence',
                        'entity' => 'isms_context',
                        'priority' => 'high',
                        'route' => 'app_context_edit',
                    ],
                ],
            ],
            'ai_leadership_policy' => [
                'name' => 'wizard.iso42001.ai_leadership_policy',
                'description' => 'wizard.iso42001.ai_leadership_policy_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_leadership_policy_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_leadership_policy_enhanced',
                'icon' => 'bell',
                'weight' => 1.5,
                'checks' => [
                    'iso42001_policy' => [
                        'name' => 'wizard.check.iso42001_policy',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'ai_inventory' => [
                'name' => 'wizard.iso42001.ai_inventory',
                'description' => 'wizard.iso42001.ai_inventory_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_inventory_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_inventory_enhanced',
                'icon' => 'nav-list-check',
                'weight' => 3,
                'checks' => [
                    // filters assetType = 'ai_agent'
                    'iso42001_ai_inventory' => [
                        'name' => 'wizard.check.iso42001_ai_inventory',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'ai_risk' => [
                'name' => 'wizard.iso42001.ai_risk',
                'description' => 'wizard.iso42001.ai_risk_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_risk_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_risk_enhanced',
                'icon' => 'status-critical',
                'weight' => 3,
                'checks' => [
                    'iso42001_ai_risk' => [
                        'name' => 'wizard.check.iso42001_ai_risk',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'route' => 'app_risk_index',
                    ],
                ],
            ],
            'ai_data_governance' => [
                'name' => 'wizard.iso42001.ai_data_governance',
                'description' => 'wizard.iso42001.ai_data_governance_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_data_governance_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_data_governance_enhanced',
                'icon' => 'nav-database',
                'weight' => 2,
                'checks' => [
                    'iso42001_data_governance' => [
                        'name' => 'wizard.check.iso42001_data_governance',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'ai_human_oversight' => [
                'name' => 'wizard.iso42001.ai_human_oversight',
                'description' => 'wizard.iso42001.ai_human_oversight_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_human_oversight_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_human_oversight_enhanced',
                'icon' => 'ui-eye',
                'weight' => 2,
                'checks' => [
                    'iso42001_oversight' => [
                        'name' => 'wizard.check.iso42001_oversight',
                        'type' => 'entity_presence',
                        'entity' => 'asset',
                        'priority' => 'high',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'ai_transparency' => [
                'name' => 'wizard.iso42001.ai_transparency',
                'description' => 'wizard.iso42001.ai_transparency_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_transparency_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_transparency_enhanced',
                'icon' => 'status-info',
                'weight' => 1.5,
                'checks' => [
                    'iso42001_transparency' => [
                        'name' => 'wizard.check.iso42001_transparency',
                        'type' => 'entity_presence',
                        'entity' => 'document',
                        'priority' => 'high',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'ai_incident_response' => [
                'name' => 'wizard.iso42001.ai_incident_response',
                'description' => 'wizard.iso42001.ai_incident_response_desc',
                'maturity_baseline' => 'wizard.iso42001.ai_incident_response_baseline',
                'maturity_enhanced' => 'wizard.iso42001.ai_incident_response_enhanced',
                'icon' => 'nav-shield-alert',
                'weight' => 1.5,
                'checks' => [
                    'iso42001_incidents' => [
                        'name' => 'wizard.check.iso42001_incidents',
                        'type' => 'incident_process',
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
        ];
    }
}
