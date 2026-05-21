<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\CategoryProvider;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraExitStrategyDocumentedCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraExtensionCoverageCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraIctRiskFrameworkPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraIncidentReportingDeadlinesCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraThirdPartyRegisterMaintainedCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraTlptCadenceCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Dora\DoraValidityFromCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\A534ThinHostPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\DataBreachNotification72hCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\DpiaMethodologyPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\DpoCharterAppointedCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\DsrProcedurePresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\GdprSectionCoverageCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\PrivacyPolicyPresentCheck;
use App\Service\ComplianceWizard\Check\PolicyWizard\Privacy\RopaMethodologyPresentCheck;
use App\Service\TenantContext;

/**
 * EuRegulatoryFrameworkCategoryProvider
 *
 * Extracted from ComplianceWizardService (god-class decomposition).
 * Provides category definitions for EU regulatory frameworks:
 * - NIS2 Directive (EU 2022/2555)
 * - DORA (Digital Operational Resilience Act)
 * - GDPR/DSGVO
 * - KRITIS / NIS2-DE-Umsetzung
 * - EU AI Act (Verordnung 2024/1689)
 * - ENISA EUCS (European Cybersecurity Certification Scheme for Cloud Services)
 * - EU Cyber Resilience Act (Verordnung 2024/2847)
 */
final class EuRegulatoryFrameworkCategoryProvider
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ?\App\Repository\TenantPolicySettingRepository $tenantPolicySettingRepository = null,
    ) {
    }

    /**
     * NIS2 Directive (EU 2022/2555) Categories
     *
     * Based on NIS2 Articles:
     * - Article 20: Governance
     * - Article 21: Cybersecurity risk-management measures (10 areas)
     * - Article 23: Reporting obligations (24h/72h/1 month)
     * - Article 24: Use of European cybersecurity certification schemes
     */
    public function getNis2Categories(): array
    {
        return [
            // Article 20: Governance
            'governance' => [
                'name' => 'wizard.nis2.governance',
                'description' => 'wizard.nis2.governance_desc',
                'maturity_baseline' => 'wizard.nis2.governance_baseline',
                'maturity_enhanced' => 'wizard.nis2.governance_enhanced',
                'icon' => 'nav-building',
                'weight' => 1.5,
                'article' => '20',
                'checks' => [
                    'management_approval' => [
                        'name' => 'wizard.check.nis2_20_1_approval',
                        'description' => 'wizard.check.nis2_20_1_approval_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '20(1)',
                    ],
                    'management_training' => [
                        'name' => 'wizard.check.nis2_20_2_training',
                        'description' => 'wizard.check.nis2_20_2_training_desc',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'priority' => 'critical',
                        'article' => '20(2)',
                        'route' => 'app_training_index',
                    ],
                ],
            ],

            // Article 21(2)(a): Risk analysis and information system security policies
            'risk_policies' => [
                'name' => 'wizard.nis2.risk_policies',
                'description' => 'wizard.nis2.risk_policies_desc',
                'maturity_baseline' => 'wizard.nis2.risk_policies_baseline',
                'maturity_enhanced' => 'wizard.nis2.risk_policies_enhanced',
                'icon' => 'nav-file-earmark-text',
                'weight' => 2,
                'article' => '21(2)(a)',
                'checks' => [
                    'risk_analysis' => [
                        'name' => 'wizard.check.nis2_21_2a_risk',
                        'description' => 'wizard.check.nis2_21_2a_risk_desc',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'priority' => 'critical',
                        'route' => 'app_risk_index',
                    ],
                    'security_policies' => [
                        'name' => 'wizard.check.nis2_21_2a_policies',
                        'description' => 'wizard.check.nis2_21_2a_policies_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.1', '5.2', '5.3'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Article 21(2)(b): Incident handling
            'incident_handling' => [
                'name' => 'wizard.nis2.incident_handling',
                'description' => 'wizard.nis2.incident_handling_desc',
                'maturity_baseline' => 'wizard.nis2.incident_handling_baseline',
                'maturity_enhanced' => 'wizard.nis2.incident_handling_enhanced',
                'icon' => 'status-warning',
                'weight' => 2,
                'article' => '21(2)(b)',
                'checks' => [
                    'incident_process' => [
                        'name' => 'wizard.check.nis2_21_2b_incident',
                        'description' => 'wizard.check.nis2_21_2b_incident_desc',
                        'type' => 'incident_process',
                        'sla_hours' => 24,
                        'module' => 'incidents',
                        'priority' => 'critical',
                        'route' => 'app_incident_index',
                    ],
                    'incident_controls' => [
                        'name' => 'wizard.check.nis2_21_2b_controls',
                        'description' => 'wizard.check.nis2_21_2b_controls_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.24', '5.25', '5.26', '5.27', '5.28'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Article 21(2)(c): Business continuity and crisis management
            'business_continuity' => [
                'name' => 'wizard.nis2.business_continuity',
                'description' => 'wizard.nis2.business_continuity_desc',
                'maturity_baseline' => 'wizard.nis2.business_continuity_baseline',
                'maturity_enhanced' => 'wizard.nis2.business_continuity_enhanced',
                'icon' => 'util-refresh',
                'weight' => 2,
                'article' => '21(2)(c)',
                'checks' => [
                    'bcm_coverage' => [
                        'name' => 'wizard.check.nis2_21_2c_bcm',
                        'description' => 'wizard.check.nis2_21_2c_bcm_desc',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'priority' => 'critical',
                        'route' => 'app_bcm_index',
                    ],
                    'backup_recovery' => [
                        'name' => 'wizard.check.nis2_21_2c_backup',
                        'description' => 'wizard.check.nis2_21_2c_backup_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.13', '8.14'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_soa_index',
                    ],
                    'crisis_management' => [
                        'name' => 'wizard.check.nis2_21_2c_crisis',
                        'description' => 'wizard.check.nis2_21_2c_crisis_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.29', '5.30'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Article 21(2)(d): Supply chain security
            'supply_chain' => [
                'name' => 'wizard.nis2.supply_chain',
                'description' => 'wizard.nis2.supply_chain_desc',
                'maturity_baseline' => 'wizard.nis2.supply_chain_baseline',
                'maturity_enhanced' => 'wizard.nis2.supply_chain_enhanced',
                'icon' => 'nav-truck',
                'weight' => 1.5,
                'article' => '21(2)(d)',
                'checks' => [
                    'supplier_security' => [
                        'name' => 'wizard.check.nis2_21_2d_supplier',
                        'description' => 'wizard.check.nis2_21_2d_supplier_desc',
                        'type' => 'supplier_assessment',
                        'module' => 'assets',
                        'priority' => 'high',
                        'route' => 'app_supplier_index',
                    ],
                    'supplier_controls' => [
                        'name' => 'wizard.check.nis2_21_2d_controls',
                        'description' => 'wizard.check.nis2_21_2d_controls_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.19', '5.20', '5.21', '5.22', '5.23'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Article 21(2)(e): Security in network and information systems acquisition, development and maintenance
            'secure_development' => [
                'name' => 'wizard.nis2.secure_development',
                'description' => 'wizard.nis2.secure_development_desc',
                'maturity_baseline' => 'wizard.nis2.secure_development_baseline',
                'maturity_enhanced' => 'wizard.nis2.secure_development_enhanced',
                'icon' => 'asset-application',
                'weight' => 1.5,
                'article' => '21(2)(e)',
                'checks' => [
                    'secure_development' => [
                        'name' => 'wizard.check.nis2_21_2e_development',
                        'description' => 'wizard.check.nis2_21_2e_development_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.25', '8.26', '8.27', '8.28', '8.29', '8.30', '8.31', '8.32', '8.33'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                    'vulnerability_handling' => [
                        'name' => 'wizard.check.nis2_21_2e_vulnerability',
                        'description' => 'wizard.check.nis2_21_2e_vulnerability_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.8'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_vulnerability_index',
                    ],
                ],
            ],

            // Article 21(2)(f): Policies and procedures for effectiveness assessment
            'effectiveness_assessment' => [
                'name' => 'wizard.nis2.effectiveness_assessment',
                'description' => 'wizard.nis2.effectiveness_assessment_desc',
                'maturity_baseline' => 'wizard.nis2.effectiveness_assessment_baseline',
                'maturity_enhanced' => 'wizard.nis2.effectiveness_assessment_enhanced',
                'icon' => 'nav-clipboard-check',
                'weight' => 1.5,
                'article' => '21(2)(f)',
                'checks' => [
                    'security_testing' => [
                        'name' => 'wizard.check.nis2_21_2f_testing',
                        'description' => 'wizard.check.nis2_21_2f_testing_desc',
                        'type' => 'audit_status',
                        'module' => 'audits',
                        'priority' => 'high',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],

            // Article 21(2)(g): Basic cyber hygiene practices and cybersecurity training
            'cyber_hygiene' => [
                'name' => 'wizard.nis2.cyber_hygiene',
                'description' => 'wizard.nis2.cyber_hygiene_desc',
                'maturity_baseline' => 'wizard.nis2.cyber_hygiene_baseline',
                'maturity_enhanced' => 'wizard.nis2.cyber_hygiene_enhanced',
                'icon' => 'nav-mortarboard',
                'weight' => 1.5,
                'article' => '21(2)(g)',
                'checks' => [
                    'awareness_training' => [
                        'name' => 'wizard.check.nis2_21_2g_training',
                        'description' => 'wizard.check.nis2_21_2g_training_desc',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'priority' => 'critical',
                        'route' => 'app_training_index',
                    ],
                    'hygiene_controls' => [
                        'name' => 'wizard.check.nis2_21_2g_hygiene',
                        'description' => 'wizard.check.nis2_21_2g_hygiene_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['6.3', '8.1', '8.5', '8.7'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Article 21(2)(h): Policies and procedures regarding use of cryptography and encryption
            'cryptography' => [
                'name' => 'wizard.nis2.cryptography',
                'description' => 'wizard.nis2.cryptography_desc',
                'maturity_baseline' => 'wizard.nis2.cryptography_baseline',
                'maturity_enhanced' => 'wizard.nis2.cryptography_enhanced',
                'icon' => 'ui-lock',
                'weight' => 1.5,
                'article' => '21(2)(h)',
                'checks' => [
                    'crypto_controls' => [
                        'name' => 'wizard.check.nis2_21_2h_crypto',
                        'description' => 'wizard.check.nis2_21_2h_crypto_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.24'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Article 21(2)(i): Human resources security, access control policies and asset management
            'access_and_assets' => [
                'name' => 'wizard.nis2.access_and_assets',
                'description' => 'wizard.nis2.access_and_assets_desc',
                'maturity_baseline' => 'wizard.nis2.access_and_assets_baseline',
                'maturity_enhanced' => 'wizard.nis2.access_and_assets_enhanced',
                'icon' => 'ui-key',
                'weight' => 2,
                'article' => '21(2)(i)',
                'checks' => [
                    'hr_security' => [
                        'name' => 'wizard.check.nis2_21_2i_hr',
                        'description' => 'wizard.check.nis2_21_2i_hr_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['6.1', '6.2', '6.4', '6.5', '6.6'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                    'access_control' => [
                        'name' => 'wizard.check.nis2_21_2i_access',
                        'description' => 'wizard.check.nis2_21_2i_access_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.15', '5.16', '5.17', '5.18', '8.2', '8.3', '8.4', '8.5'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_soa_index',
                    ],
                    'asset_management' => [
                        'name' => 'wizard.check.nis2_21_2i_assets',
                        'description' => 'wizard.check.nis2_21_2i_assets_desc',
                        'type' => 'asset_coverage',
                        'module' => 'assets',
                        'priority' => 'critical',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],

            // Article 21(2)(j): Use of multi-factor authentication or continuous authentication solutions
            'mfa' => [
                'name' => 'wizard.nis2.mfa',
                'description' => 'wizard.nis2.mfa_desc',
                'maturity_baseline' => 'wizard.nis2.mfa_baseline',
                'maturity_enhanced' => 'wizard.nis2.mfa_enhanced',
                'icon' => 'nav-shield-lock',
                'weight' => 1.5,
                'article' => '21(2)(j)',
                'checks' => [
                    'mfa_implementation' => [
                        'name' => 'wizard.check.nis2_21_2j_mfa',
                        'description' => 'wizard.check.nis2_21_2j_mfa_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.5'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'route' => 'app_soa_index',
                    ],
                    'secure_communications' => [
                        'name' => 'wizard.check.nis2_21_2j_comms',
                        'description' => 'wizard.check.nis2_21_2j_comms_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.20', '8.21', '8.22'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Article 23: Reporting obligations
            'reporting' => [
                'name' => 'wizard.nis2.reporting',
                'description' => 'wizard.nis2.reporting_desc',
                'maturity_baseline' => 'wizard.nis2.reporting_baseline',
                'maturity_enhanced' => 'wizard.nis2.reporting_enhanced',
                'icon' => 'bell',
                'weight' => 2,
                'article' => '23',
                'checks' => [
                    'early_warning' => [
                        'name' => 'wizard.check.nis2_23_early_warning',
                        'description' => 'wizard.check.nis2_23_early_warning_desc',
                        'type' => 'incident_process',
                        'sla_hours' => 24,
                        'module' => 'incidents',
                        'priority' => 'critical',
                        'article' => '23(4)(a)',
                        'route' => 'app_incident_index',
                    ],
                    'incident_notification' => [
                        'name' => 'wizard.check.nis2_23_notification',
                        'description' => 'wizard.check.nis2_23_notification_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '23(4)(b)',
                    ],
                    'final_report' => [
                        'name' => 'wizard.check.nis2_23_final_report',
                        'description' => 'wizard.check.nis2_23_final_report_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'article' => '23(4)(d)',
                    ],
                ],
            ],
        ];
    }

    /**
     * DORA (EU 2022/2554) Categories - Digital Operational Resilience Act
     *
     * Based on DORA's 5 pillars:
     * - Chapter II (Art. 5-16): ICT Risk Management
     * - Chapter III (Art. 17-23): ICT-related incident management and reporting
     * - Chapter IV (Art. 24-27): Digital operational resilience testing
     * - Chapter V (Art. 28-44): Managing of ICT third-party risk
     * - Chapter VI (Art. 45): Information-sharing arrangements
     */
    public function getDoraCategories(): array
    {
        $categories = [
            // Chapter II, Section I: ICT Risk Management Framework (Art. 5-6)
            'governance' => [
                'name' => 'wizard.dora.governance',
                'description' => 'wizard.dora.governance_desc',
                'maturity_baseline' => 'wizard.dora.governance_baseline',
                'maturity_enhanced' => 'wizard.dora.governance_enhanced',
                'icon' => 'nav-building',
                'weight' => 2,
                'article' => '5-6',
                'checks' => [
                    'management_body' => [
                        'name' => 'wizard.check.dora_5_management',
                        'description' => 'wizard.check.dora_5_management_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '5(2)',
                    ],
                    'ict_risk_framework' => [
                        'name' => 'wizard.check.dora_6_framework',
                        'description' => 'wizard.check.dora_6_framework_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '6(1)',
                    ],
                    'ict_risk_strategy' => [
                        'name' => 'wizard.check.dora_6_strategy',
                        'description' => 'wizard.check.dora_6_strategy_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '6(8)',
                    ],
                ],
            ],

            // Chapter II, Section I: ICT Systems, Protocols and Tools (Art. 7)
            'ict_systems' => [
                'name' => 'wizard.dora.ict_systems',
                'description' => 'wizard.dora.ict_systems_desc',
                'maturity_baseline' => 'wizard.dora.ict_systems_baseline',
                'maturity_enhanced' => 'wizard.dora.ict_systems_enhanced',
                'icon' => 'asset-network',
                'weight' => 2,
                'article' => '7',
                'checks' => [
                    'ict_inventory' => [
                        'name' => 'wizard.check.dora_7_inventory',
                        'description' => 'wizard.check.dora_7_inventory_desc',
                        'type' => 'asset_coverage',
                        'module' => 'assets',
                        'priority' => 'critical',
                        'article' => '7(1)',
                        'route' => 'app_asset_index',
                    ],
                    'network_security' => [
                        'name' => 'wizard.check.dora_7_network',
                        'description' => 'wizard.check.dora_7_network_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.20', '8.21', '8.22', '8.23'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'article' => '7(2)',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Chapter II, Section I: Identification (Art. 8)
            'identification' => [
                'name' => 'wizard.dora.identification',
                'description' => 'wizard.dora.identification_desc',
                'maturity_baseline' => 'wizard.dora.identification_baseline',
                'maturity_enhanced' => 'wizard.dora.identification_enhanced',
                'icon' => 'ui-search',
                'weight' => 2,
                'article' => '8',
                'checks' => [
                    'business_functions' => [
                        'name' => 'wizard.check.dora_8_functions',
                        'description' => 'wizard.check.dora_8_functions_desc',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'priority' => 'critical',
                        'article' => '8(1)',
                        'route' => 'app_bcm_index',
                    ],
                    'ict_assets' => [
                        'name' => 'wizard.check.dora_8_assets',
                        'description' => 'wizard.check.dora_8_assets_desc',
                        'type' => 'asset_coverage',
                        'module' => 'assets',
                        'priority' => 'critical',
                        'article' => '8(2)',
                        'route' => 'app_asset_index',
                    ],
                    'risk_sources' => [
                        'name' => 'wizard.check.dora_8_risks',
                        'description' => 'wizard.check.dora_8_risks_desc',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'priority' => 'critical',
                        'article' => '8(3)',
                        'route' => 'app_risk_index',
                    ],
                ],
            ],

            // Chapter II, Section I: Protection and Prevention (Art. 9)
            'protection' => [
                'name' => 'wizard.dora.protection',
                'description' => 'wizard.dora.protection_desc',
                'maturity_baseline' => 'wizard.dora.protection_baseline',
                'maturity_enhanced' => 'wizard.dora.protection_enhanced',
                'icon' => 'shield-check',
                'weight' => 2,
                'article' => '9',
                'checks' => [
                    'ict_security_policies' => [
                        'name' => 'wizard.check.dora_9_policies',
                        'description' => 'wizard.check.dora_9_policies_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.1', '5.2', '5.3'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'article' => '9(1)',
                        'route' => 'app_soa_index',
                    ],
                    'access_management' => [
                        'name' => 'wizard.check.dora_9_access',
                        'description' => 'wizard.check.dora_9_access_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.15', '5.16', '5.17', '5.18', '8.2', '8.3', '8.4', '8.5'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'article' => '9(4)',
                        'route' => 'app_soa_index',
                    ],
                    'cryptography' => [
                        'name' => 'wizard.check.dora_9_crypto',
                        'description' => 'wizard.check.dora_9_crypto_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.24'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'article' => '9(4)(d)',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Chapter II, Section I: Detection (Art. 10)
            'detection' => [
                'name' => 'wizard.dora.detection',
                'description' => 'wizard.dora.detection_desc',
                'maturity_baseline' => 'wizard.dora.detection_baseline',
                'maturity_enhanced' => 'wizard.dora.detection_enhanced',
                'icon' => 'ui-eye',
                'weight' => 1.5,
                'article' => '10',
                'checks' => [
                    'monitoring' => [
                        'name' => 'wizard.check.dora_10_monitoring',
                        'description' => 'wizard.check.dora_10_monitoring_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.15', '8.16'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'article' => '10(1)',
                        'route' => 'app_soa_index',
                    ],
                    'anomaly_detection' => [
                        'name' => 'wizard.check.dora_10_anomaly',
                        'description' => 'wizard.check.dora_10_anomaly_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.16'],
                        'module' => 'controls',
                        'priority' => 'high',
                        'article' => '10(2)',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Chapter II, Section I: Response and Recovery (Art. 11)
            'response_recovery' => [
                'name' => 'wizard.dora.response_recovery',
                'description' => 'wizard.dora.response_recovery_desc',
                'maturity_baseline' => 'wizard.dora.response_recovery_baseline',
                'maturity_enhanced' => 'wizard.dora.response_recovery_enhanced',
                'icon' => 'util-refresh',
                'weight' => 2,
                'article' => '11',
                'checks' => [
                    'ict_bcm_policy' => [
                        'name' => 'wizard.check.dora_11_bcm_policy',
                        'description' => 'wizard.check.dora_11_bcm_policy_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['5.29', '5.30'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'article' => '11(1)',
                        'route' => 'app_soa_index',
                    ],
                    'bcm_plans' => [
                        'name' => 'wizard.check.dora_11_plans',
                        'description' => 'wizard.check.dora_11_plans_desc',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'priority' => 'critical',
                        'article' => '11(3)',
                        'route' => 'app_bc_plan_index',
                    ],
                    'rto_rpo' => [
                        'name' => 'wizard.check.dora_11_rto_rpo',
                        'description' => 'wizard.check.dora_11_rto_rpo_desc',
                        'type' => 'bcm_coverage',
                        'module' => 'bcm',
                        'priority' => 'critical',
                        'article' => '11(4)',
                        'route' => 'app_bcm_index',
                    ],
                    'backup' => [
                        'name' => 'wizard.check.dora_11_backup',
                        'description' => 'wizard.check.dora_11_backup_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.13', '8.14'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'article' => '11(5)',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],

            // Chapter II, Section I: Learning and Evolving (Art. 13)
            'learning' => [
                'name' => 'wizard.dora.learning',
                'description' => 'wizard.dora.learning_desc',
                'maturity_baseline' => 'wizard.dora.learning_baseline',
                'maturity_enhanced' => 'wizard.dora.learning_enhanced',
                'icon' => 'ui-lightbulb',
                'weight' => 1.5,
                'article' => '13',
                'checks' => [
                    'post_incident_review' => [
                        'name' => 'wizard.check.dora_13_review',
                        'description' => 'wizard.check.dora_13_review_desc',
                        'type' => 'incident_process',
                        'sla_hours' => 720, // Post-incident review within 30 days
                        'module' => 'incidents',
                        'priority' => 'high',
                        'article' => '13(1)',
                        'route' => 'app_incident_index',
                    ],
                    'training' => [
                        'name' => 'wizard.check.dora_13_training',
                        'description' => 'wizard.check.dora_13_training_desc',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'priority' => 'high',
                        'article' => '13(6)',
                        'route' => 'app_training_index',
                    ],
                ],
            ],

            // Chapter II, Section I: Communication (Art. 14)
            'communication' => [
                'name' => 'wizard.dora.communication',
                'description' => 'wizard.dora.communication_desc',
                'maturity_baseline' => 'wizard.dora.communication_baseline',
                'maturity_enhanced' => 'wizard.dora.communication_enhanced',
                'icon' => 'bell',
                'weight' => 1,
                'article' => '14',
                'checks' => [
                    'communication_plans' => [
                        'name' => 'wizard.check.dora_14_plans',
                        'description' => 'wizard.check.dora_14_plans_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'article' => '14(1)',
                    ],
                ],
            ],

            // Chapter III: ICT-related Incident Management (Art. 17)
            'incident_management' => [
                'name' => 'wizard.dora.incident_management',
                'description' => 'wizard.dora.incident_management_desc',
                'maturity_baseline' => 'wizard.dora.incident_management_baseline',
                'maturity_enhanced' => 'wizard.dora.incident_management_enhanced',
                'icon' => 'status-warning',
                'weight' => 2,
                'article' => '17',
                'checks' => [
                    'incident_process' => [
                        'name' => 'wizard.check.dora_17_process',
                        'description' => 'wizard.check.dora_17_process_desc',
                        'type' => 'incident_process',
                        'sla_hours' => 4,
                        'module' => 'incidents',
                        'priority' => 'critical',
                        'article' => '17(1)',
                        'route' => 'app_incident_index',
                    ],
                    'incident_classification' => [
                        'name' => 'wizard.check.dora_17_classification',
                        'description' => 'wizard.check.dora_17_classification_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '17(2)',
                    ],
                ],
            ],

            // Chapter III: Incident Classification (Art. 18)
            'incident_classification' => [
                'name' => 'wizard.dora.incident_classification',
                'description' => 'wizard.dora.incident_classification_desc',
                'maturity_baseline' => 'wizard.dora.incident_classification_baseline',
                'maturity_enhanced' => 'wizard.dora.incident_classification_enhanced',
                'icon' => 'nav-tags',
                'weight' => 1.5,
                'article' => '18',
                'checks' => [
                    'classification_criteria' => [
                        'name' => 'wizard.check.dora_18_criteria',
                        'description' => 'wizard.check.dora_18_criteria_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '18(1)',
                    ],
                ],
            ],

            // Chapter III: Incident Reporting (Art. 19)
            'incident_reporting' => [
                'name' => 'wizard.dora.incident_reporting',
                'description' => 'wizard.dora.incident_reporting_desc',
                'maturity_baseline' => 'wizard.dora.incident_reporting_baseline',
                'maturity_enhanced' => 'wizard.dora.incident_reporting_enhanced',
                'icon' => 'nav-clipboard-data',
                'weight' => 2,
                'article' => '19',
                'checks' => [
                    'initial_notification' => [
                        'name' => 'wizard.check.dora_19_initial',
                        'description' => 'wizard.check.dora_19_initial_desc',
                        'type' => 'incident_process',
                        'sla_hours' => 4,
                        'module' => 'incidents',
                        'priority' => 'critical',
                        'article' => '19(4)(a)',
                        'route' => 'app_incident_index',
                    ],
                    'intermediate_report' => [
                        'name' => 'wizard.check.dora_19_intermediate',
                        'description' => 'wizard.check.dora_19_intermediate_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '19(4)(b)',
                    ],
                    'final_report' => [
                        'name' => 'wizard.check.dora_19_final',
                        'description' => 'wizard.check.dora_19_final_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '19(4)(c)',
                    ],
                ],
            ],

            // Chapter IV: Digital Operational Resilience Testing (Art. 24-25)
            'resilience_testing' => [
                'name' => 'wizard.dora.resilience_testing',
                'description' => 'wizard.dora.resilience_testing_desc',
                'maturity_baseline' => 'wizard.dora.resilience_testing_baseline',
                'maturity_enhanced' => 'wizard.dora.resilience_testing_enhanced',
                'icon' => 'bug',
                'weight' => 2,
                'article' => '24-25',
                'checks' => [
                    'testing_program' => [
                        'name' => 'wizard.check.dora_24_program',
                        'description' => 'wizard.check.dora_24_program_desc',
                        'type' => 'audit_status',
                        'module' => 'audits',
                        'priority' => 'critical',
                        'article' => '24(1)',
                        'route' => 'app_audit_index',
                    ],
                    'vulnerability_assessment' => [
                        'name' => 'wizard.check.dora_25_vulnerability',
                        'description' => 'wizard.check.dora_25_vulnerability_desc',
                        'type' => 'control_coverage',
                        'control_ids' => ['8.8'],
                        'module' => 'controls',
                        'priority' => 'critical',
                        'article' => '25(1)',
                        'route' => 'app_vulnerability_index',
                    ],
                    'penetration_testing' => [
                        'name' => 'wizard.check.dora_25_pentest',
                        'description' => 'wizard.check.dora_25_pentest_desc',
                        'type' => 'audit_status',
                        'module' => 'audits',
                        'priority' => 'high',
                        'article' => '25(1)',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],

            // Chapter IV: TLPT (Art. 26-27) - Threat-Led Penetration Testing
            'tlpt' => [
                'name' => 'wizard.dora.tlpt',
                'description' => 'wizard.dora.tlpt_desc',
                'maturity_baseline' => 'wizard.dora.tlpt_baseline',
                'maturity_enhanced' => 'wizard.dora.tlpt_enhanced',
                'icon' => 'nav-shield-alert',
                'weight' => 1.5,
                'article' => '26-27',
                'checks' => [
                    'tlpt_program' => [
                        'name' => 'wizard.check.dora_26_tlpt',
                        'description' => 'wizard.check.dora_26_tlpt_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'article' => '26(1)',
                    ],
                ],
            ],

            // Chapter V: Third-Party Risk Management (Art. 28-30)
            'third_party_risk' => [
                'name' => 'wizard.dora.third_party_risk',
                'description' => 'wizard.dora.third_party_risk_desc',
                'maturity_baseline' => 'wizard.dora.third_party_risk_baseline',
                'maturity_enhanced' => 'wizard.dora.third_party_risk_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'article' => '28-30',
                'checks' => [
                    'third_party_strategy' => [
                        'name' => 'wizard.check.dora_28_strategy',
                        'description' => 'wizard.check.dora_28_strategy_desc',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'article' => '28(2)',
                    ],
                    'ict_concentration_risk' => [
                        'name' => 'wizard.check.dora_29_concentration',
                        'description' => 'wizard.check.dora_29_concentration_desc',
                        'type' => 'supplier_assessment',
                        'module' => 'assets',
                        'priority' => 'critical',
                        'article' => '29(1)',
                        'route' => 'app_supplier_index',
                    ],
                    'register_of_information' => [
                        'name' => 'wizard.check.dora_28_register',
                        'description' => 'wizard.check.dora_28_register_desc',
                        'type' => 'supplier_assessment',
                        'module' => 'assets',
                        'priority' => 'critical',
                        'article' => '28(3)',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],

            // Chapter V: Contractual Arrangements (Art. 30)
            'contractual' => [
                'name' => 'wizard.dora.contractual',
                'description' => 'wizard.dora.contractual_desc',
                'maturity_baseline' => 'wizard.dora.contractual_baseline',
                'maturity_enhanced' => 'wizard.dora.contractual_enhanced',
                'icon' => 'nav-file-earmark-text',
                'weight' => 1.5,
                'article' => '30',
                'checks' => [
                    'contract_requirements' => [
                        'name' => 'wizard.check.dora_30_contracts',
                        'description' => 'wizard.check.dora_30_contracts_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'article' => '30(2)',
                    ],
                    'exit_strategies' => [
                        'name' => 'wizard.check.dora_30_exit',
                        'description' => 'wizard.check.dora_30_exit_desc',
                        'type' => 'manual',
                        'priority' => 'high',
                        'article' => '30(3)(f)',
                    ],
                ],
            ],

            // Policy-Wizard outputs — DORA-specific policies, deadlines + tags
            // Gated on DORA scope being active (ComplianceFramework with code
            // 'DORA' present + active OR tenant policy setting 'dora.in_scope').
            // Returns null when out-of-scope; array_filter() drops the row.
            'dora_policies' => $this->buildDoraPolicyWizardCategory(),
        ];

        // Drop categories that opted out (returned null) when DORA is not in
        // scope for the current tenant — keeps the assessment surface clean.
        return array_filter($categories, static fn ($cat): bool => $cat !== null);
    }

    /**
     * Build the "DORA Policy-Wizard outputs" category. Returns null when DORA
     * is not in scope for the tenant — see scope detection below.
     *
     * @return array<string, mixed>|null
     */
    private function buildDoraPolicyWizardCategory(): ?array
    {
        if (!$this->isDoraInScope($this->tenantContext->getCurrentTenant())) {
            return null;
        }

        $checks = [];
        foreach ([
            DoraIctRiskFrameworkPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            DoraIncidentReportingDeadlinesCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_admin_incident_sla_index'],
            DoraThirdPartyRegisterMaintainedCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_supplier_index'],
            DoraTlptCadenceCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_bc_exercise_index'],
            DoraExitStrategyDocumentedCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_supplier_index'],
            DoraValidityFromCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_document_index'],
            DoraExtensionCoverageCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
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
            'name' => 'wizard.dora.dora_policies',
            'description' => 'wizard.dora.dora_policies_desc',
            'icon' => 'nav-building',
            'weight' => 2,
            'article' => '6/19/26-27/28',
            'checks' => $checks,
        ];
    }

    /**
     * Detects whether DORA is in scope for the tenant. The signal is the
     * presence of an active {@see ComplianceFramework} with `code='DORA'`.
     *
     * Returns false for null tenants when the framework is not active
     * either — keeping the DORA category out of generic admin previews.
     * The tenant-policy-setting alternative (`dora.in_scope`) is intentionally
     * not consulted here to avoid forcing a DB lookup on every category-map
     * build; the DORA wizard is the authoritative entry-point.
     */
    private function isDoraInScope(?Tenant $tenant): bool
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'DORA']);
        if ($framework instanceof ComplianceFramework && $framework->isActive() === true) {
            return true;
        }

        // No active framework → DORA is not in scope. Tenant-specific
        // settings remain a future extension hook.
        unset($tenant); // intentional — see method PHPDoc
        return false;
    }

    public function getGdprCategories(): array
    {
        $categories = [
            'lawfulness' => [
                'name' => 'wizard.gdpr.lawfulness',
                'description' => 'wizard.gdpr.lawfulness_desc',
                'maturity_baseline' => 'wizard.gdpr.lawfulness_baseline',
                'maturity_enhanced' => 'wizard.gdpr.lawfulness_enhanced',
                'icon' => 'nav-file-earmark-text',
                'weight' => 2,
                'checks' => [
                    'processing_records' => [
                        'name' => 'wizard.check.gdpr_processing',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'description' => 'wizard.check.gdpr_processing_desc',
                    ],
                ],
            ],
            'data_subject_rights' => [
                'name' => 'wizard.gdpr.data_subject_rights',
                'description' => 'wizard.gdpr.data_subject_rights_desc',
                'maturity_baseline' => 'wizard.gdpr.data_subject_rights_baseline',
                'maturity_enhanced' => 'wizard.gdpr.data_subject_rights_enhanced',
                'icon' => 'nav-people',
                'weight' => 1.5,
                'checks' => [
                    'rights_process' => [
                        'name' => 'wizard.check.gdpr_rights',
                        'type' => 'manual',
                        'priority' => 'high',
                    ],
                ],
            ],
            'security_measures' => [
                'name' => 'wizard.gdpr.security_measures',
                'description' => 'wizard.gdpr.security_measures_desc',
                'maturity_baseline' => 'wizard.gdpr.security_measures_baseline',
                'maturity_enhanced' => 'wizard.gdpr.security_measures_enhanced',
                'icon' => 'nav-shield-lock',
                'weight' => 2,
                'checks' => [
                    'tom' => [
                        'name' => 'wizard.check.gdpr_tom',
                        'type' => 'control_coverage',
                        'module' => 'controls',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'breach_notification' => [
                'name' => 'wizard.gdpr.breach_notification',
                'description' => 'wizard.gdpr.breach_notification_desc',
                'maturity_baseline' => 'wizard.gdpr.breach_notification_baseline',
                'maturity_enhanced' => 'wizard.gdpr.breach_notification_enhanced',
                'icon' => 'status-warning',
                'weight' => 1.5,
                'checks' => [
                    'breach_process' => [
                        'name' => 'wizard.check.gdpr_breach',
                        'type' => 'incident_process',
                        'sla_hours' => 72, // GDPR: 72h
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'awareness' => [
                'name' => 'wizard.gdpr.awareness',
                'description' => 'wizard.gdpr.awareness_desc',
                'maturity_baseline' => 'wizard.gdpr.awareness_baseline',
                'maturity_enhanced' => 'wizard.gdpr.awareness_enhanced',
                'icon' => 'nav-mortarboard',
                'weight' => 1,
                'checks' => [
                    'training' => [
                        'name' => 'wizard.check.gdpr_training',
                        'type' => 'training_coverage',
                        'module' => 'training',
                        'route' => 'app_training_index',
                    ],
                ],
            ],

            // Policy-Wizard outputs — GDPR-specific policies, sections, DPO
            // appointment + breach SLA. Gated on GDPR scope being active
            // (ComplianceFramework code 'GDPR' active OR tenant policy setting
            // 'org.is_gdpr_subject' true). Returns null when out-of-scope;
            // array_filter() drops the row.
            'gdpr_policies' => $this->buildGdprPolicyWizardCategory(),
        ];

        return array_filter($categories, static fn ($cat): bool => $cat !== null);
    }

    /**
     * Build the "GDPR Policy-Wizard outputs" category. Returns null when GDPR
     * scope is not declared for the tenant.
     *
     * @return array<string, mixed>|null
     */
    private function buildGdprPolicyWizardCategory(): ?array
    {
        if (!$this->isGdprInScope($this->tenantContext->getCurrentTenant())) {
            return null;
        }

        $checks = [];
        foreach ([
            PrivacyPolicyPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            RopaMethodologyPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            DpiaMethodologyPresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            DsrProcedurePresentCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            DataBreachNotification72hCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_admin_incident_sla_index'],
            DpoCharterAppointedCheck::CHECK_ID => ['priority' => 'critical', 'route' => 'app_policy_wizard_index'],
            GdprSectionCoverageCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
            A534ThinHostPresentCheck::CHECK_ID => ['priority' => 'high', 'route' => 'app_policy_wizard_index'],
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
            'name' => 'wizard.gdpr.gdpr_policies',
            'description' => 'wizard.gdpr.gdpr_policies_desc',
            'icon' => 'nav-people',
            'weight' => 2,
            'article' => 'GDPR Art. 5/24/30/33-34/35/37-39 + ISO 27001 A.5.34',
            'checks' => $checks,
        ];
    }

    /**
     * Detects whether GDPR is in scope for the tenant. Signals:
     * - ComplianceFramework with `code='GDPR'` and `isActive=true`, OR
     * - Tenant policy setting `org.is_gdpr_subject` set to true.
     *
     * Returns false when neither signal is present (keeps the GDPR category
     * out of generic admin previews).
     */
    private function isGdprInScope(?Tenant $tenant): bool
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'GDPR']);
        if ($framework instanceof ComplianceFramework && $framework->isActive() === true) {
            return true;
        }

        if ($tenant === null || $this->tenantPolicySettingRepository === null) {
            return false;
        }
        $setting = $this->tenantPolicySettingRepository->findOneByTenantAndKey(
            $tenant,
            'org.is_gdpr_subject',
        );
        if ($setting === null) {
            return false;
        }
        $value = $setting->getValue();
        return $value === true || $value === 'true' || $value === 1 || $value === '1';
    }

    /**
     * KRITIS / NIS2-DE-Umsetzung categories (BSI-Kritisverordnung + NIS2-Umsetzungsgesetz)
     *
     * Covers obligations for critical infrastructure operators in Germany:
     * KRITIS threshold check, state of the art, 24h incident reporting,
     * BCM, biannual audit proof, top-management training, supply chain security.
     */
    public function getKritisCategories(): array
    {
        return [
            'scope_determination' => [
                'name' => 'wizard.kritis.scope_determination',
                'description' => 'wizard.kritis.scope_determination_desc',
                'maturity_baseline' => 'wizard.kritis.scope_determination_baseline',
                'maturity_enhanced' => 'wizard.kritis.scope_determination_enhanced',
                'icon' => 'nav-bullseye',
                'weight' => 2,
                'checks' => [
                    'kritis_scope' => [
                        'name' => 'wizard.check.kritis_scope',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'route' => 'app_context_edit',
                    ],
                ],
            ],
            'state_of_the_art' => [
                'name' => 'wizard.kritis.state_of_the_art',
                'description' => 'wizard.kritis.state_of_the_art_desc',
                'maturity_baseline' => 'wizard.kritis.state_of_the_art_baseline',
                'maturity_enhanced' => 'wizard.kritis.state_of_the_art_enhanced',
                'icon' => 'ui-stars',
                'weight' => 3,
                'checks' => [
                    'kritis_state_of_art' => [
                        'name' => 'wizard.check.kritis_state_of_art',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'incident_reporting' => [
                'name' => 'wizard.kritis.incident_reporting',
                'description' => 'wizard.kritis.incident_reporting_desc',
                'maturity_baseline' => 'wizard.kritis.incident_reporting_baseline',
                'maturity_enhanced' => 'wizard.kritis.incident_reporting_enhanced',
                'icon' => 'bell',
                'weight' => 3,
                'checks' => [
                    'kritis_incident_reporting' => [
                        'name' => 'wizard.check.kritis_incident_reporting',
                        'type' => 'incident_process',
                        'sla_hours' => 24,
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'bcm_kritis' => [
                'name' => 'wizard.kritis.bcm_kritis',
                'description' => 'wizard.kritis.bcm_kritis_desc',
                'maturity_baseline' => 'wizard.kritis.bcm_kritis_baseline',
                'maturity_enhanced' => 'wizard.kritis.bcm_kritis_enhanced',
                'icon' => 'recovery',
                'weight' => 2,
                'checks' => [
                    'kritis_bcm' => [
                        'name' => 'wizard.check.kritis_bcm',
                        'type' => 'bcm_coverage',
                        'route' => 'app_bcm_index',
                    ],
                ],
            ],
            'audit_proof' => [
                'name' => 'wizard.kritis.audit_proof',
                'description' => 'wizard.kritis.audit_proof_desc',
                'maturity_baseline' => 'wizard.kritis.audit_proof_baseline',
                'maturity_enhanced' => 'wizard.kritis.audit_proof_enhanced',
                'icon' => 'nav-clipboard-data',
                'weight' => 2,
                'checks' => [
                    'kritis_audit_proof' => [
                        'name' => 'wizard.check.kritis_audit_proof',
                        'type' => 'audit_status',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'top_management' => [
                'name' => 'wizard.kritis.top_management',
                'description' => 'wizard.kritis.top_management_desc',
                'maturity_baseline' => 'wizard.kritis.top_management_baseline',
                'maturity_enhanced' => 'wizard.kritis.top_management_enhanced',
                'icon' => 'nav-people',
                'weight' => 1.5,
                'checks' => [
                    'kritis_top_mgmt' => [
                        'name' => 'wizard.check.kritis_top_mgmt',
                        'type' => 'training_coverage',
                        'route' => 'app_training_index',
                    ],
                ],
            ],
            'supplier_due_diligence' => [
                'name' => 'wizard.kritis.supplier_due_diligence',
                'description' => 'wizard.kritis.supplier_due_diligence_desc',
                'maturity_baseline' => 'wizard.kritis.supplier_due_diligence_baseline',
                'maturity_enhanced' => 'wizard.kritis.supplier_due_diligence_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'kritis_suppliers' => [
                        'name' => 'wizard.check.kritis_suppliers',
                        'type' => 'supplier_assessment',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * EU AI Act (Verordnung 2024/1689) Categories.
     * Articles 5-50 + 51-55 (GPAI) + 72 (Post-Market Monitoring).
     * Risk-based: prohibited (Art 5) + high-risk (Art 6, Annex III) + transparency (Art 50).
     */
    public function getEuAiActCategories(): array
    {
        return [
            'prohibited_practices' => [
                'name' => 'wizard.eu_ai_act.prohibited_practices',
                'description' => 'wizard.eu_ai_act.prohibited_practices_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.prohibited_practices_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.prohibited_practices_enhanced',
                'icon' => 'status-critical',
                'weight' => 3,
                'checks' => [
                    'eu_ai_act_prohibited' => [
                        'name' => 'wizard.check.eu_ai_act_prohibited',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'high_risk_classification' => [
                'name' => 'wizard.eu_ai_act.high_risk_classification',
                'description' => 'wizard.eu_ai_act.high_risk_classification_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.high_risk_classification_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.high_risk_classification_enhanced',
                'icon' => 'status-warning',
                'weight' => 3,
                'checks' => [
                    'eu_ai_act_classification' => [
                        'name' => 'wizard.check.eu_ai_act_classification',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'risk_management_system' => [
                'name' => 'wizard.eu_ai_act.risk_management_system',
                'description' => 'wizard.eu_ai_act.risk_management_system_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.risk_management_system_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.risk_management_system_enhanced',
                'icon' => 'nav-bar-chart',
                'weight' => 2.5,
                'checks' => [
                    'eu_ai_act_rms' => [
                        'name' => 'wizard.check.eu_ai_act_rms',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'route' => 'app_risk_index',
                    ],
                ],
            ],
            'data_governance' => [
                'name' => 'wizard.eu_ai_act.data_governance',
                'description' => 'wizard.eu_ai_act.data_governance_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.data_governance_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.data_governance_enhanced',
                'icon' => 'nav-database',
                'weight' => 2,
                'checks' => [
                    'eu_ai_act_data_governance' => [
                        'name' => 'wizard.check.eu_ai_act_data_governance',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'technical_documentation' => [
                'name' => 'wizard.eu_ai_act.technical_documentation',
                'description' => 'wizard.eu_ai_act.technical_documentation_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.technical_documentation_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.technical_documentation_enhanced',
                'icon' => 'nav-file-earmark-text',
                'weight' => 2,
                'checks' => [
                    'eu_ai_act_tech_doc' => [
                        'name' => 'wizard.check.eu_ai_act_tech_doc',
                        'type' => 'document_review',
                        'document_categories' => ['technical', 'manual'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'human_oversight' => [
                'name' => 'wizard.eu_ai_act.human_oversight',
                'description' => 'wizard.eu_ai_act.human_oversight_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.human_oversight_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.human_oversight_enhanced',
                'icon' => 'ui-eye',
                'weight' => 2,
                'checks' => [
                    'eu_ai_act_oversight' => [
                        'name' => 'wizard.check.eu_ai_act_oversight',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'accuracy_robustness' => [
                'name' => 'wizard.eu_ai_act.accuracy_robustness',
                'description' => 'wizard.eu_ai_act.accuracy_robustness_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.accuracy_robustness_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.accuracy_robustness_enhanced',
                'icon' => 'shield-check',
                'weight' => 2,
                'checks' => [
                    'eu_ai_act_accuracy' => [
                        'name' => 'wizard.check.eu_ai_act_accuracy',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'transparency_obligations' => [
                'name' => 'wizard.eu_ai_act.transparency_obligations',
                'description' => 'wizard.eu_ai_act.transparency_obligations_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.transparency_obligations_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.transparency_obligations_enhanced',
                'icon' => 'status-info',
                'weight' => 1.5,
                'checks' => [
                    'eu_ai_act_transparency' => [
                        'name' => 'wizard.check.eu_ai_act_transparency',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'gpai_obligations' => [
                'name' => 'wizard.eu_ai_act.gpai_obligations',
                'description' => 'wizard.eu_ai_act.gpai_obligations_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.gpai_obligations_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.gpai_obligations_enhanced',
                'icon' => 'nav-process',
                'weight' => 1.5,
                'checks' => [
                    'eu_ai_act_gpai' => [
                        'name' => 'wizard.check.eu_ai_act_gpai',
                        'type' => 'manual',
                        'priority' => 'medium',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'conformity_assessment' => [
                'name' => 'wizard.eu_ai_act.conformity_assessment',
                'description' => 'wizard.eu_ai_act.conformity_assessment_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.conformity_assessment_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.conformity_assessment_enhanced',
                'icon' => 'nav-clipboard-check',
                'weight' => 1.5,
                'checks' => [
                    'eu_ai_act_conformity' => [
                        'name' => 'wizard.check.eu_ai_act_conformity',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'post_market_monitoring' => [
                'name' => 'wizard.eu_ai_act.post_market_monitoring',
                'description' => 'wizard.eu_ai_act.post_market_monitoring_desc',
                'maturity_baseline' => 'wizard.eu_ai_act.post_market_monitoring_baseline',
                'maturity_enhanced' => 'wizard.eu_ai_act.post_market_monitoring_enhanced',
                'icon' => 'nav-activity',
                'weight' => 1.5,
                'checks' => [
                    'eu_ai_act_pmm' => [
                        'name' => 'wizard.check.eu_ai_act_pmm',
                        'type' => 'incident_process',
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * ENISA EUCS Categories (European Cybersecurity Certification Scheme for Cloud Services).
     * Aligned to Substantial assurance level. ENISA Draft published 2020, ongoing work.
     */
    public function getEucsCategories(): array
    {
        return [
            'organization_security' => [
                'name' => 'wizard.eucs.organization_security',
                'description' => 'wizard.eucs.organization_security_desc',
                'maturity_baseline' => 'wizard.eucs.organization_security_baseline',
                'maturity_enhanced' => 'wizard.eucs.organization_security_enhanced',
                'icon' => 'nav-process',
                'weight' => 2,
                'checks' => [
                    'eucs_organization' => [
                        'name' => 'wizard.check.eucs_organization',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'risk_management' => [
                'name' => 'wizard.eucs.risk_management',
                'description' => 'wizard.eucs.risk_management_desc',
                'maturity_baseline' => 'wizard.eucs.risk_management_baseline',
                'maturity_enhanced' => 'wizard.eucs.risk_management_enhanced',
                'icon' => 'nav-bar-chart',
                'weight' => 2.5,
                'checks' => [
                    'eucs_risk' => [
                        'name' => 'wizard.check.eucs_risk',
                        'type' => 'risk_coverage',
                        'module' => 'risks',
                        'route' => 'app_risk_index',
                    ],
                ],
            ],
            'asset_management' => [
                'name' => 'wizard.eucs.asset_management',
                'description' => 'wizard.eucs.asset_management_desc',
                'maturity_baseline' => 'wizard.eucs.asset_management_baseline',
                'maturity_enhanced' => 'wizard.eucs.asset_management_enhanced',
                'icon' => 'archive',
                'weight' => 2,
                'checks' => [
                    'eucs_assets' => [
                        'name' => 'wizard.check.eucs_assets',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'identity_access' => [
                'name' => 'wizard.eucs.identity_access',
                'description' => 'wizard.eucs.identity_access_desc',
                'maturity_baseline' => 'wizard.eucs.identity_access_baseline',
                'maturity_enhanced' => 'wizard.eucs.identity_access_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'eucs_iam' => [
                        'name' => 'wizard.check.eucs_iam',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'cryptography' => [
                'name' => 'wizard.eucs.cryptography',
                'description' => 'wizard.eucs.cryptography_desc',
                'maturity_baseline' => 'wizard.eucs.cryptography_baseline',
                'maturity_enhanced' => 'wizard.eucs.cryptography_enhanced',
                'icon' => 'ui-key',
                'weight' => 1.5,
                'checks' => [
                    'eucs_crypto' => [
                        'name' => 'wizard.check.eucs_crypto',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'operations_security' => [
                'name' => 'wizard.eucs.operations_security',
                'description' => 'wizard.eucs.operations_security_desc',
                'maturity_baseline' => 'wizard.eucs.operations_security_baseline',
                'maturity_enhanced' => 'wizard.eucs.operations_security_enhanced',
                'icon' => 'nav-gear',
                'weight' => 2,
                'checks' => [
                    'eucs_operations' => [
                        'name' => 'wizard.check.eucs_operations',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'communication_security' => [
                'name' => 'wizard.eucs.communication_security',
                'description' => 'wizard.eucs.communication_security_desc',
                'maturity_baseline' => 'wizard.eucs.communication_security_baseline',
                'maturity_enhanced' => 'wizard.eucs.communication_security_enhanced',
                'icon' => 'bell',
                'weight' => 1.5,
                'checks' => [
                    'eucs_communication' => [
                        'name' => 'wizard.check.eucs_communication',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'portability_interoperability' => [
                'name' => 'wizard.eucs.portability_interoperability',
                'description' => 'wizard.eucs.portability_interoperability_desc',
                'maturity_baseline' => 'wizard.eucs.portability_interoperability_baseline',
                'maturity_enhanced' => 'wizard.eucs.portability_interoperability_enhanced',
                'icon' => 'util-refresh',
                'weight' => 1,
                'checks' => [
                    'eucs_portability' => [
                        'name' => 'wizard.check.eucs_portability',
                        'type' => 'manual',
                        'priority' => 'medium',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'incident_management' => [
                'name' => 'wizard.eucs.incident_management',
                'description' => 'wizard.eucs.incident_management_desc',
                'maturity_baseline' => 'wizard.eucs.incident_management_baseline',
                'maturity_enhanced' => 'wizard.eucs.incident_management_enhanced',
                'icon' => 'nav-shield-alert',
                'weight' => 2,
                'checks' => [
                    'eucs_incidents' => [
                        'name' => 'wizard.check.eucs_incidents',
                        'type' => 'incident_process',
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'business_continuity' => [
                'name' => 'wizard.eucs.business_continuity',
                'description' => 'wizard.eucs.business_continuity_desc',
                'maturity_baseline' => 'wizard.eucs.business_continuity_baseline',
                'maturity_enhanced' => 'wizard.eucs.business_continuity_enhanced',
                'icon' => 'util-refresh',
                'weight' => 1.5,
                'checks' => [
                    'eucs_bcm' => [
                        'name' => 'wizard.check.eucs_bcm',
                        'type' => 'manual',
                        'priority' => 'high',
                        'module' => 'bcm',
                        'route' => 'app_business_continuity_plan_index',
                    ],
                ],
            ],
            'supplier_management' => [
                'name' => 'wizard.eucs.supplier_management',
                'description' => 'wizard.eucs.supplier_management_desc',
                'maturity_baseline' => 'wizard.eucs.supplier_management_baseline',
                'maturity_enhanced' => 'wizard.eucs.supplier_management_enhanced',
                'icon' => 'nav-building',
                'weight' => 1.5,
                'checks' => [
                    'eucs_suppliers' => [
                        'name' => 'wizard.check.eucs_suppliers',
                        'type' => 'manual',
                        'priority' => 'high',
                        'module' => 'suppliers',
                        'route' => 'app_supplier_index',
                    ],
                ],
            ],
            'compliance_audit' => [
                'name' => 'wizard.eucs.compliance_audit',
                'description' => 'wizard.eucs.compliance_audit_desc',
                'maturity_baseline' => 'wizard.eucs.compliance_audit_baseline',
                'maturity_enhanced' => 'wizard.eucs.compliance_audit_enhanced',
                'icon' => 'nav-clipboard-check',
                'weight' => 1.5,
                'checks' => [
                    'eucs_compliance' => [
                        'name' => 'wizard.check.eucs_compliance',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * EU Cyber Resilience Act Categories (Verordnung 2024/2847).
     * Annex I Part I (Security) + Part II (Vulnerability handling) + Art. 11 (Disclosure) +
     * Art. 13 (CE marking) + Art. 14 (24h vulnerability reporting to ENISA).
     * Verbindlich ab 11.12.2027 fuer Produkte mit digitalen Elementen.
     */
    public function getCraCategories(): array
    {
        return [
            'security_by_design' => [
                'name' => 'wizard.cra.security_by_design',
                'description' => 'wizard.cra.security_by_design_desc',
                'maturity_baseline' => 'wizard.cra.security_by_design_baseline',
                'maturity_enhanced' => 'wizard.cra.security_by_design_enhanced',
                'icon' => 'nav-shield-check',
                'weight' => 3,
                'checks' => [
                    'cra_security_by_design' => [
                        'name' => 'wizard.check.cra_security_by_design',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'vulnerability_handling' => [
                'name' => 'wizard.cra.vulnerability_handling',
                'description' => 'wizard.cra.vulnerability_handling_desc',
                'maturity_baseline' => 'wizard.cra.vulnerability_handling_baseline',
                'maturity_enhanced' => 'wizard.cra.vulnerability_handling_enhanced',
                'icon' => 'bug',
                'weight' => 3,
                'checks' => [
                    'cra_vuln_handling' => [
                        'name' => 'wizard.check.cra_vuln_handling',
                        'type' => 'manual',
                        'priority' => 'critical',
                        'module' => 'vulnerability_intel',
                        'route' => 'app_vulnerability_index',
                    ],
                ],
            ],
            'sbom_supply_chain' => [
                'name' => 'wizard.cra.sbom_supply_chain',
                'description' => 'wizard.cra.sbom_supply_chain_desc',
                'maturity_baseline' => 'wizard.cra.sbom_supply_chain_baseline',
                'maturity_enhanced' => 'wizard.cra.sbom_supply_chain_enhanced',
                'icon' => 'nav-list-check',
                'weight' => 2.5,
                'checks' => [
                    'cra_sbom' => [
                        'name' => 'wizard.check.cra_sbom',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'vulnerability_disclosure' => [
                'name' => 'wizard.cra.vulnerability_disclosure',
                'description' => 'wizard.cra.vulnerability_disclosure_desc',
                'maturity_baseline' => 'wizard.cra.vulnerability_disclosure_baseline',
                'maturity_enhanced' => 'wizard.cra.vulnerability_disclosure_enhanced',
                'icon' => 'bell',
                'weight' => 2,
                'checks' => [
                    'cra_disclosure' => [
                        'name' => 'wizard.check.cra_disclosure',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'incident_reporting' => [
                'name' => 'wizard.cra.incident_reporting',
                'description' => 'wizard.cra.incident_reporting_desc',
                'maturity_baseline' => 'wizard.cra.incident_reporting_baseline',
                'maturity_enhanced' => 'wizard.cra.incident_reporting_enhanced',
                'icon' => 'bell',
                'weight' => 2.5,
                'checks' => [
                    'cra_incident_reporting' => [
                        'name' => 'wizard.check.cra_incident_reporting',
                        'type' => 'incident_process',
                        'module' => 'incidents',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'ce_marking_conformity' => [
                'name' => 'wizard.cra.ce_marking_conformity',
                'description' => 'wizard.cra.ce_marking_conformity_desc',
                'maturity_baseline' => 'wizard.cra.ce_marking_conformity_baseline',
                'maturity_enhanced' => 'wizard.cra.ce_marking_conformity_enhanced',
                'icon' => 'nav-patch-check',
                'weight' => 1.5,
                'checks' => [
                    'cra_ce_marking' => [
                        'name' => 'wizard.check.cra_ce_marking',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'technical_documentation' => [
                'name' => 'wizard.cra.technical_documentation',
                'description' => 'wizard.cra.technical_documentation_desc',
                'maturity_baseline' => 'wizard.cra.technical_documentation_baseline',
                'maturity_enhanced' => 'wizard.cra.technical_documentation_enhanced',
                'icon' => 'nav-file-earmark-text',
                'weight' => 1.5,
                'checks' => [
                    'cra_tech_doc' => [
                        'name' => 'wizard.check.cra_tech_doc',
                        'type' => 'document_review',
                        'document_categories' => ['technical', 'manual'],
                        'module' => 'documents',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'support_period' => [
                'name' => 'wizard.cra.support_period',
                'description' => 'wizard.cra.support_period_desc',
                'maturity_baseline' => 'wizard.cra.support_period_baseline',
                'maturity_enhanced' => 'wizard.cra.support_period_enhanced',
                'icon' => 'nav-clock-history',
                'weight' => 1,
                'checks' => [
                    'cra_support_period' => [
                        'name' => 'wizard.check.cra_support_period',
                        'type' => 'manual',
                        'priority' => 'medium',
                        'route' => 'app_document_index',
                    ],
                ],
            ],
        ];
    }
}
