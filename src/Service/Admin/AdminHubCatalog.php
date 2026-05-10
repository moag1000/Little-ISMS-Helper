<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * IA-Definition für das Admin-Panel-Hub-Layout.
 *
 * Sieben Gruppen × ~36 Module gemäß
 * docs/design_system/sections/admin-panel.html. Jede Gruppe hat eine
 * Tone-Farbe (cyan / pink / purple) für die linke Akzent-Bar der
 * Hub-Cards. Module verweisen auf existierende Routes; fehlende
 * Routen kommen mit `route: null` zurück und werden im Template als
 * Coming-Soon-Disabled-Cards gerendert.
 *
 * Service ist read-only und stateless — keine Counts werden hier
 * abgefragt, dafür ist eine separate Aggregation in der Controller-
 * Schicht zuständig (Sprint 2).
 */
class AdminHubCatalog
{
    /**
     * @return list<array{
     *     key: string,
     *     tone: string,
     *     icon: string,
     *     label: string,
     *     description: string,
     *     modules: list<array{
     *         key: string,
     *         icon: string,
     *         label: string,
     *         description: string,
     *         route: string|null,
     *         routeParams?: array<string, mixed>,
     *         tone?: string,
     *         badge?: string,
     *         coming_soon?: bool,
     *     }>,
     * }>
     */
    public function getGroups(): array
    {
        return [
            [
                'key' => 'organisation',
                'tone' => 'cyan',
                'icon' => 'buildings',
                'label' => 'admin.hub.group.organisation.label',
                'description' => 'admin.hub.group.organisation.desc',
                'modules' => [
                    [
                        'key' => 'tenants',
                        'icon' => 'buildings',
                        'label' => 'admin.hub.module.tenants.label',
                        'description' => 'admin.hub.module.tenants.desc',
                        'route' => 'tenant_management_index',
                    ],
                    [
                        'key' => 'corporate_structure',
                        'icon' => 'diagram-3',
                        'label' => 'admin.hub.module.corporate_structure.label',
                        'description' => 'admin.hub.module.corporate_structure.desc',
                        'route' => 'tenant_management_corporate_structure',
                    ],
                    [
                        'key' => 'locations',
                        'icon' => 'geo-alt',
                        'label' => 'admin.hub.module.locations.label',
                        'description' => 'admin.hub.module.locations.desc',
                        'route' => 'app_location_index',
                    ],
                    [
                        'key' => 'tenant_branding',
                        'icon' => 'envelope-paper',
                        'label' => 'admin.hub.module.tenant_branding.label',
                        'description' => 'admin.hub.module.tenant_branding.desc',
                        'route' => 'app_admin_tenant_email_branding',
                    ],
                    [
                        'key' => 'tenant_compliance_settings',
                        'icon' => 'sliders',
                        'label' => 'admin.hub.module.tenant_compliance_settings.label',
                        'description' => 'admin.hub.module.tenant_compliance_settings.desc',
                        'route' => 'admin_tenant_compliance_settings_current',
                    ],
                    [
                        'key' => 'report_style',
                        'icon' => 'file-earmark-bar-graph',
                        'label' => 'admin.hub.module.report_style.label',
                        'description' => 'admin.hub.module.report_style.desc',
                        'route' => 'app_admin_report_style_edit',
                    ],
                ],
            ],
            [
                'key' => 'identity',
                'tone' => 'pink',
                'icon' => 'shield-lock',
                'label' => 'admin.hub.group.identity.label',
                'description' => 'admin.hub.group.identity.desc',
                'modules' => [
                    [
                        'key' => 'users',
                        'icon' => 'people',
                        'label' => 'admin.hub.module.users.label',
                        'description' => 'admin.hub.module.users.desc',
                        'route' => 'user_management_index',
                    ],
                    [
                        'key' => 'roles',
                        'icon' => 'person-badge',
                        'label' => 'admin.hub.module.roles.label',
                        'description' => 'admin.hub.module.roles.desc',
                        'route' => 'role_management_index',
                    ],
                    [
                        'key' => 'permissions',
                        'icon' => 'shield-check',
                        'label' => 'admin.hub.module.permissions.label',
                        'description' => 'admin.hub.module.permissions.desc',
                        'route' => 'permission_index',
                    ],
                    [
                        'key' => 'sso',
                        'icon' => 'plug',
                        'label' => 'admin.hub.module.sso.label',
                        'description' => 'admin.hub.module.sso.desc',
                        'route' => 'admin_sso_index',
                    ],
                    [
                        'key' => 'sessions',
                        'icon' => 'hourglass-split',
                        'label' => 'admin.hub.module.sessions.label',
                        'description' => 'admin.hub.module.sessions.desc',
                        'route' => 'session_index',
                    ],
                    [
                        'key' => 'mfa',
                        'icon' => 'phone',
                        'label' => 'admin.hub.module.mfa.label',
                        'description' => 'admin.hub.module.mfa.desc',
                        'route' => 'admin_mfa_index',
                    ],
                ],
            ],
            [
                'key' => 'isms_data',
                'tone' => 'cyan',
                'icon' => 'shield-check',
                'label' => 'admin.hub.group.isms_data.label',
                'description' => 'admin.hub.group.isms_data.desc',
                'modules' => [
                    [
                        'key' => 'frameworks',
                        'icon' => 'collection',
                        'label' => 'admin.hub.module.frameworks.label',
                        'description' => 'admin.hub.module.frameworks.desc',
                        'route' => 'admin_compliance_index',
                    ],
                    [
                        'key' => 'mappings',
                        'icon' => 'arrow-left-right',
                        'label' => 'admin.hub.module.mappings.label',
                        'description' => 'admin.hub.module.mappings.desc',
                        'route' => 'app_compliance_mapping_index',
                    ],
                    [
                        'key' => 'mapping_quality',
                        'icon' => 'graph-up',
                        'label' => 'admin.hub.module.mapping_quality.label',
                        'description' => 'admin.hub.module.mapping_quality.desc',
                        'route' => 'admin_mapping_quality_index',
                    ],
                    [
                        'key' => 'industry_baselines',
                        'icon' => 'stack',
                        'label' => 'admin.hub.module.industry_baselines.label',
                        'description' => 'admin.hub.module.industry_baselines.desc',
                        'route' => 'admin_industry_baselines_index',
                    ],
                    [
                        'key' => 'tags',
                        'icon' => 'tags',
                        'label' => 'admin.hub.module.tags.label',
                        'description' => 'admin.hub.module.tags.desc',
                        'route' => 'admin_tag_index',
                    ],
                ],
            ],
            [
                'key' => 'audit_compliance',
                'tone' => 'purple',
                'icon' => 'journal-check',
                'label' => 'admin.hub.group.audit_compliance.label',
                'description' => 'admin.hub.group.audit_compliance.desc',
                'modules' => [
                    [
                        'key' => 'audit_log',
                        'icon' => 'list-columns-reverse',
                        'label' => 'admin.hub.module.audit_log.label',
                        'description' => 'admin.hub.module.audit_log.desc',
                        'route' => 'app_audit_log_index',
                    ],
                    [
                        'key' => 'audit_retention',
                        'icon' => 'archive',
                        'label' => 'admin.hub.module.audit_retention.label',
                        'description' => 'admin.hub.module.audit_retention.desc',
                        'route' => 'app_admin_audit_retention',
                    ],
                    [
                        'key' => 'compliance_policy',
                        'icon' => 'file-earmark-ruled',
                        'label' => 'admin.hub.module.compliance_policy.label',
                        'description' => 'admin.hub.module.compliance_policy.desc',
                        'route' => 'admin_compliance_policy_index',
                    ],
                    [
                        'key' => 'risk_approval_config',
                        'icon' => 'check2-square',
                        'label' => 'admin.hub.module.risk_approval_config.label',
                        'description' => 'admin.hub.module.risk_approval_config.desc',
                        'route' => 'app_admin_risk_approval_config',
                    ],
                    [
                        'key' => 'incident_sla',
                        'icon' => 'speedometer',
                        'label' => 'admin.hub.module.incident_sla.label',
                        'description' => 'admin.hub.module.incident_sla.desc',
                        'route' => 'app_admin_incident_sla_config',
                    ],
                    [
                        'key' => 'audit_log_monitoring',
                        'icon' => 'activity',
                        'label' => 'admin.hub.module.audit_log_monitoring.label',
                        'description' => 'admin.hub.module.audit_log_monitoring.desc',
                        'route' => 'monitoring_audit_log',
                    ],
                    [
                        'key' => 'workflow_definitions',
                        'icon' => 'diagram-2',
                        'label' => 'admin.hub.module.workflow_definitions.label',
                        'description' => 'admin.hub.module.workflow_definitions.desc',
                        'route' => 'app_workflow_definitions',
                    ],
                    [
                        'key' => 'scheduled_reports',
                        'icon' => 'calendar-event',
                        'label' => 'admin.hub.module.scheduled_reports.label',
                        'description' => 'admin.hub.module.scheduled_reports.desc',
                        'route' => 'app_scheduled_report_index',
                    ],
                ],
            ],
            [
                'key' => 'integrations',
                'tone' => 'cyan',
                'icon' => 'plug',
                'label' => 'admin.hub.group.integrations.label',
                'description' => 'admin.hub.group.integrations.desc',
                'modules' => [
                    [
                        'key' => 'compliance_import',
                        'icon' => 'cloud-arrow-up',
                        'label' => 'admin.hub.module.compliance_import.label',
                        'description' => 'admin.hub.module.compliance_import.desc',
                        'route' => 'admin_compliance_import_upload',
                    ],
                    [
                        'key' => 'gstool_import',
                        'icon' => 'box-arrow-in-down',
                        'label' => 'admin.hub.module.gstool_import.label',
                        'description' => 'admin.hub.module.gstool_import.desc',
                        'route' => 'admin_gstool_import_index',
                    ],
                    [
                        'key' => 'sample_data',
                        'icon' => 'database-add',
                        'label' => 'admin.hub.module.sample_data.label',
                        'description' => 'admin.hub.module.sample_data.desc',
                        'route' => 'admin_sample_data_index',
                    ],
                    [
                        'key' => 'import_history',
                        'icon' => 'clock-history',
                        'label' => 'admin.hub.module.import_history.label',
                        'description' => 'admin.hub.module.import_history.desc',
                        'route' => 'admin_import_history_index',
                    ],
                ],
            ],
            [
                'key' => 'system',
                'tone' => 'purple',
                'icon' => 'cpu',
                'label' => 'admin.hub.group.system.label',
                'description' => 'admin.hub.group.system.desc',
                'modules' => [
                    [
                        'key' => 'system_health',
                        'icon' => 'heart-pulse',
                        'label' => 'admin.hub.module.system_health.label',
                        'description' => 'admin.hub.module.system_health.desc',
                        'route' => 'monitoring_health',
                    ],
                    [
                        'key' => 'modules',
                        'icon' => 'puzzle',
                        'label' => 'admin.hub.module.modules.label',
                        'description' => 'admin.hub.module.modules.desc',
                        'route' => 'admin_modules_index',
                    ],
                    [
                        'key' => 'data_repair',
                        'icon' => 'wrench-adjustable',
                        'label' => 'admin.hub.module.data_repair.label',
                        'description' => 'admin.hub.module.data_repair.desc',
                        'route' => 'admin_data_repair_index',
                    ],
                    [
                        'key' => 'quick_fix_settings',
                        'icon' => 'lightning',
                        'label' => 'admin.hub.module.quick_fix_settings.label',
                        'description' => 'admin.hub.module.quick_fix_settings.desc',
                        'route' => 'app_admin_quick_fix_settings',
                    ],
                    [
                        'key' => 'kpi_threshold',
                        'icon' => 'sliders',
                        'label' => 'admin.hub.module.kpi_threshold.label',
                        'description' => 'admin.hub.module.kpi_threshold.desc',
                        'route' => 'admin_kpi_threshold_index',
                    ],
                    [
                        'key' => 'loader_fixer',
                        'icon' => 'tools',
                        'label' => 'admin.hub.module.loader_fixer.label',
                        'description' => 'admin.hub.module.loader_fixer.desc',
                        'route' => 'admin_loader_fixer_index',
                    ],
                    [
                        'key' => 'monitoring_performance',
                        'icon' => 'speedometer2',
                        'label' => 'admin.hub.module.monitoring_performance.label',
                        'description' => 'admin.hub.module.monitoring_performance.desc',
                        'route' => 'monitoring_performance',
                    ],
                    [
                        'key' => 'licensing',
                        'icon' => 'patch-check',
                        'label' => 'admin.hub.module.licensing.label',
                        'description' => 'admin.hub.module.licensing.desc',
                        'route' => 'admin_licensing_index',
                    ],
                    [
                        'key' => 'application_settings',
                        'icon' => 'gear',
                        'label' => 'admin.hub.module.application_settings.label',
                        'description' => 'admin.hub.module.application_settings.desc',
                        'route' => 'admin_settings_index',
                    ],
                    [
                        'key' => 'data_backup',
                        'icon' => 'database-down',
                        'label' => 'admin.hub.module.data_backup.label',
                        'description' => 'admin.hub.module.data_backup.desc',
                        'route' => 'data_backup_index',
                    ],
                    [
                        'key' => 'monitoring_errors',
                        'icon' => 'exclamation-triangle',
                        'label' => 'admin.hub.module.monitoring_errors.label',
                        'description' => 'admin.hub.module.monitoring_errors.desc',
                        'route' => 'monitoring_errors',
                    ],
                    [
                        'key' => 'api_doc',
                        'icon' => 'code-square',
                        'label' => 'admin.hub.module.api_doc.label',
                        'description' => 'admin.hub.module.api_doc.desc',
                        'route' => 'api_doc',
                    ],
                    [
                        'key' => 'setup_wizard',
                        'icon' => 'magic',
                        'label' => 'admin.hub.module.setup_wizard.label',
                        'description' => 'admin.hub.module.setup_wizard.desc',
                        'route' => 'setup_wizard_index',
                    ],
                ],
            ],
            [
                'key' => 'branding_ux',
                'tone' => 'pink',
                'icon' => 'palette',
                'label' => 'admin.hub.group.branding_ux.label',
                'description' => 'admin.hub.group.branding_ux.desc',
                'modules' => [
                    [
                        'key' => 'supplier_criticality',
                        'icon' => 'speedometer',
                        'label' => 'admin.hub.module.supplier_criticality.label',
                        'description' => 'admin.hub.module.supplier_criticality.desc',
                        'route' => 'app_admin_supplier_criticality_index',
                    ],
                    [
                        'key' => 'tour_content',
                        'icon' => 'book',
                        'label' => 'admin.hub.module.tour_content.label',
                        'description' => 'admin.hub.module.tour_content.desc',
                        'route' => 'admin_tour_content_index',
                    ],
                    [
                        'key' => 'tour_completion',
                        'icon' => 'mortarboard',
                        'label' => 'admin.hub.module.tour_completion.label',
                        'description' => 'admin.hub.module.tour_completion.desc',
                        'route' => 'admin_tour_completion_index',
                    ],
                    [
                        'key' => 'alva_hint_stats',
                        'icon' => 'stars',
                        'label' => 'admin.hub.module.alva_hint_stats.label',
                        'description' => 'admin.hub.module.alva_hint_stats.desc',
                        'route' => 'app_alva_hint_stats',
                    ],
                ],
            ],
        ];
    }
}
