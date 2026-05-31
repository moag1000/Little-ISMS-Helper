<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\CategoryProvider;

/**
 * OtherFrameworkCategoryProvider
 *
 * Extracted from ComplianceWizardService (god-class decomposition).
 * Provides category definitions for frameworks not covered by the specialized
 * ISO, EU-Regulatory, or BSI providers:
 * - TISAX (Trusted Information Security Assessment Exchange)
 * - NIST CSF 2.0 (NIST Cybersecurity Framework)
 * - PCI-DSS v4.0.1 (Payment Card Industry Data Security Standard)
 * - SOC 2 Type II (AICPA Trust Services)
 *
 * All methods are pure data (no database/service dependencies).
 */
final class OtherFrameworkCategoryProvider
{
    public function getTisaxCategories(): array
    {
        return [
            'information_security' => [
                'name' => 'wizard.tisax.information_security',
                'description' => 'wizard.tisax.information_security_desc',
                'maturity_baseline' => 'wizard.tisax.information_security_baseline',
                'maturity_enhanced' => 'wizard.tisax.information_security_enhanced',
                'icon' => 'nav-shield-lock',
                'weight' => 2,
                'checks' => [
                    'isms_controls' => [
                        'name' => 'wizard.check.tisax_controls',
                        'type' => 'maturity_coverage',
                        'framework' => 'TISAX',
                        'tier' => 'information_security',
                        'module' => 'controls',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'prototype_protection' => [
                'name' => 'wizard.tisax.prototype_protection',
                'description' => 'wizard.tisax.prototype_protection_desc',
                'maturity_baseline' => 'wizard.tisax.prototype_protection_baseline',
                'maturity_enhanced' => 'wizard.tisax.prototype_protection_enhanced',
                'icon' => 'nav-truck',
                'weight' => 1.5,
                'checks' => [
                    'asset_protection' => [
                        'name' => 'wizard.check.tisax_assets',
                        'type' => 'maturity_coverage',
                        'framework' => 'TISAX',
                        'tier' => 'prototype_protection',
                        'module' => 'assets',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'data_protection' => [
                'name' => 'wizard.tisax.data_protection',
                'description' => 'wizard.tisax.data_protection_desc',
                'maturity_baseline' => 'wizard.tisax.data_protection_baseline',
                'maturity_enhanced' => 'wizard.tisax.data_protection_enhanced',
                'icon' => 'nav-people',
                'weight' => 1.5,
                'checks' => [
                    'privacy_controls' => [
                        'name' => 'wizard.check.tisax_privacy',
                        'type' => 'maturity_coverage',
                        'framework' => 'TISAX',
                        'tier' => 'data_protection',
                        'module' => 'controls',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * NIST Cybersecurity Framework 2.0 categories (6 Functions: GV/ID/PR/DE/RS/RC)
     *
     * Covers the six core functions of NIST CSF 2.0 including the new Govern function.
     */
    public function getNistCsfCategories(): array
    {
        return [
            'govern' => [
                'name' => 'wizard.nist_csf.govern',
                'description' => 'wizard.nist_csf.govern_desc',
                'maturity_baseline' => 'wizard.nist_csf.govern_baseline',
                'maturity_enhanced' => 'wizard.nist_csf.govern_enhanced',
                'icon' => 'nav-patch-check',
                'weight' => 2,
                'checks' => [
                    'nist_csf_govern' => [
                        'name' => 'wizard.check.nist_csf_govern',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'identify' => [
                'name' => 'wizard.nist_csf.identify',
                'description' => 'wizard.nist_csf.identify_desc',
                'maturity_baseline' => 'wizard.nist_csf.identify_baseline',
                'maturity_enhanced' => 'wizard.nist_csf.identify_enhanced',
                'icon' => 'ui-search',
                'weight' => 2,
                'checks' => [
                    'nist_csf_identify' => [
                        'name' => 'wizard.check.nist_csf_identify',
                        'type' => 'asset_coverage',
                        'route' => 'app_asset_index',
                    ],
                ],
            ],
            'protect' => [
                'name' => 'wizard.nist_csf.protect',
                'description' => 'wizard.nist_csf.protect_desc',
                'maturity_baseline' => 'wizard.nist_csf.protect_baseline',
                'maturity_enhanced' => 'wizard.nist_csf.protect_enhanced',
                'icon' => 'nav-shield-lock',
                'weight' => 3,
                'checks' => [
                    'nist_csf_protect' => [
                        'name' => 'wizard.check.nist_csf_protect',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'detect' => [
                'name' => 'wizard.nist_csf.detect',
                'description' => 'wizard.nist_csf.detect_desc',
                'maturity_baseline' => 'wizard.nist_csf.detect_baseline',
                'maturity_enhanced' => 'wizard.nist_csf.detect_enhanced',
                'icon' => 'ui-eye',
                'weight' => 2,
                'checks' => [
                    'nist_csf_detect' => [
                        'name' => 'wizard.check.nist_csf_detect',
                        'type' => 'incident_process',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'respond' => [
                'name' => 'wizard.nist_csf.respond',
                'description' => 'wizard.nist_csf.respond_desc',
                'maturity_baseline' => 'wizard.nist_csf.respond_baseline',
                'maturity_enhanced' => 'wizard.nist_csf.respond_enhanced',
                'icon' => 'status-warning',
                'weight' => 2,
                'checks' => [
                    'nist_csf_respond' => [
                        'name' => 'wizard.check.nist_csf_respond',
                        'type' => 'incident_process',
                        'route' => 'app_incident_index',
                    ],
                ],
            ],
            'recover' => [
                'name' => 'wizard.nist_csf.recover',
                'description' => 'wizard.nist_csf.recover_desc',
                'maturity_baseline' => 'wizard.nist_csf.recover_baseline',
                'maturity_enhanced' => 'wizard.nist_csf.recover_enhanced',
                'icon' => 'util-refresh',
                'weight' => 2,
                'checks' => [
                    'nist_csf_recover' => [
                        'name' => 'wizard.check.nist_csf_recover',
                        'type' => 'bcm_coverage',
                        'route' => 'app_bcm_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * PCI-DSS v4.0.1 categories — 12 Requirements mapped to ISMS check types.
     *
     * Covers all 12 PCI-DSS Requirements of the Payment Card Industry Data Security Standard v4.0.1.
     */
    public function getPciDssCategories(): array
    {
        return [
            'req_1_network' => [
                'name' => 'wizard.pci_dss.req_1_network',
                'description' => 'wizard.pci_dss.req_1_network_desc',
                'icon' => 'asset-network',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_1' => [
                        'name' => 'wizard.check.pci_dss_req_1',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_2_secure_config' => [
                'name' => 'wizard.pci_dss.req_2_secure_config',
                'description' => 'wizard.pci_dss.req_2_secure_config_desc',
                'icon' => 'nav-gear',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_2' => [
                        'name' => 'wizard.check.pci_dss_req_2',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_3_protect_data' => [
                'name' => 'wizard.pci_dss.req_3_protect_data',
                'description' => 'wizard.pci_dss.req_3_protect_data_desc',
                'icon' => 'nav-shield-lock',
                'weight' => 3,
                'checks' => [
                    'pci_dss_req_3' => [
                        'name' => 'wizard.check.pci_dss_req_3',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_4_transit' => [
                'name' => 'wizard.pci_dss.req_4_transit',
                'description' => 'wizard.pci_dss.req_4_transit_desc',
                'icon' => 'nav-shield',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_4' => [
                        'name' => 'wizard.check.pci_dss_req_4',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_5_malware' => [
                'name' => 'wizard.pci_dss.req_5_malware',
                'description' => 'wizard.pci_dss.req_5_malware_desc',
                'icon' => 'bug',
                'weight' => 1.5,
                'checks' => [
                    'pci_dss_req_5' => [
                        'name' => 'wizard.check.pci_dss_req_5',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_6_secure_dev' => [
                'name' => 'wizard.pci_dss.req_6_secure_dev',
                'description' => 'wizard.pci_dss.req_6_secure_dev_desc',
                'icon' => 'asset-application',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_6' => [
                        'name' => 'wizard.check.pci_dss_req_6',
                        'type' => 'document_review',
                        'document_categories' => ['policy', 'concept'],
                        'route' => 'app_document_index',
                    ],
                ],
            ],
            'req_7_access' => [
                'name' => 'wizard.pci_dss.req_7_access',
                'description' => 'wizard.pci_dss.req_7_access_desc',
                'icon' => 'ui-key',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_7' => [
                        'name' => 'wizard.check.pci_dss_req_7',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_8_idauth' => [
                'name' => 'wizard.pci_dss.req_8_idauth',
                'description' => 'wizard.pci_dss.req_8_idauth_desc',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_8' => [
                        'name' => 'wizard.check.pci_dss_req_8',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_9_physical' => [
                'name' => 'wizard.pci_dss.req_9_physical',
                'description' => 'wizard.pci_dss.req_9_physical_desc',
                'icon' => 'nav-building',
                'weight' => 1.5,
                'checks' => [
                    'pci_dss_req_9' => [
                        'name' => 'wizard.check.pci_dss_req_9',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_location_index',
                    ],
                ],
            ],
            'req_10_logging' => [
                'name' => 'wizard.pci_dss.req_10_logging',
                'description' => 'wizard.pci_dss.req_10_logging_desc',
                'icon' => 'nav-journal-text',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_10' => [
                        'name' => 'wizard.check.pci_dss_req_10',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'req_11_test' => [
                'name' => 'wizard.pci_dss.req_11_test',
                'description' => 'wizard.pci_dss.req_11_test_desc',
                'icon' => 'nav-clipboard-check',
                'weight' => 2,
                'checks' => [
                    'pci_dss_req_11' => [
                        'name' => 'wizard.check.pci_dss_req_11',
                        'type' => 'audit_status',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'req_12_policy' => [
                'name' => 'wizard.pci_dss.req_12_policy',
                'description' => 'wizard.pci_dss.req_12_policy_desc',
                'icon' => 'nav-file-earmark-text',
                'weight' => 1.5,
                'checks' => [
                    'pci_dss_req_12' => [
                        'name' => 'wizard.check.pci_dss_req_12',
                        'type' => 'document_review',
                        'document_categories' => ['policy'],
                        'route' => 'app_document_index',
                    ],
                ],
            ],
        ];
    }

    /**
     * SOC 2 Type II categories — 5 AICPA Trust Services Criteria.
     *
     * Covers Security (mandatory), Availability, Processing Integrity, Confidentiality, Privacy.
     */
    public function getSoc2Categories(): array
    {
        return [
            'security' => [
                'name' => 'wizard.soc2.security',
                'description' => 'wizard.soc2.security_desc',
                'maturity_baseline' => 'wizard.soc2.security_baseline',
                'maturity_enhanced' => 'wizard.soc2.security_enhanced',
                'icon' => 'shield-check',
                'weight' => 3,
                'checks' => [
                    'soc2_security' => [
                        'name' => 'wizard.check.soc2_security',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'availability' => [
                'name' => 'wizard.soc2.availability',
                'description' => 'wizard.soc2.availability_desc',
                'maturity_baseline' => 'wizard.soc2.availability_baseline',
                'maturity_enhanced' => 'wizard.soc2.availability_enhanced',
                'icon' => 'recovery',
                'weight' => 2,
                'checks' => [
                    'soc2_availability' => [
                        'name' => 'wizard.check.soc2_availability',
                        'type' => 'bcm_coverage',
                        'route' => 'app_bcm_index',
                    ],
                ],
            ],
            'processing_integrity' => [
                'name' => 'wizard.soc2.processing_integrity',
                'description' => 'wizard.soc2.processing_integrity_desc',
                'maturity_baseline' => 'wizard.soc2.processing_integrity_baseline',
                'maturity_enhanced' => 'wizard.soc2.processing_integrity_enhanced',
                'icon' => 'ui-check',
                'weight' => 2,
                'checks' => [
                    'soc2_processing_integrity' => [
                        'name' => 'wizard.check.soc2_processing_integrity',
                        'type' => 'manual',
                        'priority' => 'high',
                        'route' => 'app_audit_index',
                    ],
                ],
            ],
            'confidentiality' => [
                'name' => 'wizard.soc2.confidentiality',
                'description' => 'wizard.soc2.confidentiality_desc',
                'maturity_baseline' => 'wizard.soc2.confidentiality_baseline',
                'maturity_enhanced' => 'wizard.soc2.confidentiality_enhanced',
                'icon' => 'data-personal',
                'weight' => 2,
                'checks' => [
                    'soc2_confidentiality' => [
                        'name' => 'wizard.check.soc2_confidentiality',
                        'type' => 'control_coverage',
                        'route' => 'app_soa_index',
                    ],
                ],
            ],
            'privacy' => [
                'name' => 'wizard.soc2.privacy',
                'description' => 'wizard.soc2.privacy_desc',
                'maturity_baseline' => 'wizard.soc2.privacy_baseline',
                'maturity_enhanced' => 'wizard.soc2.privacy_enhanced',
                'icon' => 'nav-people',
                'weight' => 2,
                'checks' => [
                    'soc2_privacy' => [
                        'name' => 'wizard.check.soc2_privacy',
                        'type' => 'dsr_coverage',
                        'route' => 'app_data_subject_request_index',
                    ],
                ],
            ],
        ];
    }
}
