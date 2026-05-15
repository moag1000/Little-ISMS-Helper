<?php

declare(strict_types=1);

namespace App\Service\Admin;

/**
 * IA-Definition für das Admin-Panel-Hub-Layout.
 *
 * Acht Gruppen × ~59 Module gemäß
 * docs/design_system/sections/admin-panel.html. Jede Gruppe hat eine
 * Tone-Farbe (cyan / pink / purple) für die linke Akzent-Bar der
 * Hub-Cards. Module verweisen auf existierende Routes; fehlende
 * Routen kommen mit `route: null` zurück und werden im Template als
 * Coming-Soon-Disabled-Cards gerendert.
 *
 * Service ist read-only und stateless — keine Counts werden hier
 * abgefragt, dafür ist eine separate Aggregation in der Controller-
 * Schicht zuständig (Sprint 2).
 *
 * Icon-values are full Aurora CSS class names (fa-icon--<name>).
 * Bootstrap-icons were removed in PR #368; all icons reference Aurora only.
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
                'icon' => 'fa-icon--nav-building',
                'label' => 'admin.hub.group.organisation.label',
                'description' => 'admin.hub.group.organisation.desc',
                'modules' => [
                    [
                        'key' => 'tenants',
                        'icon' => 'fa-icon--nav-building',
                        'label' => 'admin.hub.module.tenants.label',
                        'description' => 'admin.hub.module.tenants.desc',
                        'route' => 'tenant_management_index',
                    ],
                    [
                        'key' => 'corporate_structure',
                        'icon' => 'fa-icon--nav-process',
                        'label' => 'admin.hub.module.corporate_structure.label',
                        'description' => 'admin.hub.module.corporate_structure.desc',
                        'route' => 'tenant_management_corporate_structure',
                    ],
                    [
                        'key' => 'locations',
                        'icon' => 'fa-icon--geo-alt',
                        'label' => 'admin.hub.module.locations.label',
                        'description' => 'admin.hub.module.locations.desc',
                        'route' => 'app_location_index',
                    ],
                    [
                        'key' => 'tenant_branding',
                        'icon' => 'fa-icon--nav-envelope',
                        'label' => 'admin.hub.module.tenant_branding.label',
                        'description' => 'admin.hub.module.tenant_branding.desc',
                        'route' => 'app_admin_tenant_email_branding',
                    ],
                    [
                        'key' => 'tenant_compliance_settings',
                        'icon' => 'fa-icon--nav-sliders',
                        'label' => 'admin.hub.module.tenant_compliance_settings.label',
                        'description' => 'admin.hub.module.tenant_compliance_settings.desc',
                        'route' => 'admin_tenant_compliance_settings_current',
                    ],
                    [
                        'key' => 'policy_style',
                        'icon' => 'fa-icon--nav-palette',
                        'label' => 'admin.hub.module.policy_style.label',
                        'description' => 'admin.hub.module.policy_style.desc',
                        'route' => 'app_admin_policy_style_edit',
                    ],
                    [
                        'key' => 'report_style',
                        'icon' => 'fa-icon--nav-bar-chart',
                        'label' => 'admin.hub.module.report_style.label',
                        'description' => 'admin.hub.module.report_style.desc',
                        'route' => 'app_admin_report_style_edit',
                    ],
                ],
            ],
            [
                'key' => 'identity',
                'tone' => 'pink',
                'icon' => 'fa-icon--nav-shield-lock',
                'label' => 'admin.hub.group.identity.label',
                'description' => 'admin.hub.group.identity.desc',
                'modules' => [
                    [
                        'key' => 'users',
                        'icon' => 'fa-icon--nav-people',
                        'label' => 'admin.hub.module.users.label',
                        'description' => 'admin.hub.module.users.desc',
                        'route' => 'user_management_index',
                    ],
                    [
                        'key' => 'roles',
                        'icon' => 'fa-icon--role',
                        'label' => 'admin.hub.module.roles.label',
                        'description' => 'admin.hub.module.roles.desc',
                        'route' => 'role_management_index',
                    ],
                    [
                        'key' => 'permissions',
                        'icon' => 'fa-icon--nav-shield-check',
                        'label' => 'admin.hub.module.permissions.label',
                        'description' => 'admin.hub.module.permissions.desc',
                        'route' => 'permission_index',
                    ],
                    [
                        'key' => 'sso',
                        'icon' => 'fa-icon--sso',
                        'label' => 'admin.hub.module.sso.label',
                        'description' => 'admin.hub.module.sso.desc',
                        'route' => 'admin_sso_index',
                    ],
                    [
                        'key' => 'sessions',
                        'icon' => 'fa-icon--nav-sessions',
                        'label' => 'admin.hub.module.sessions.label',
                        'description' => 'admin.hub.module.sessions.desc',
                        'route' => 'session_index',
                    ],
                    [
                        'key' => 'mfa',
                        'icon' => 'fa-icon--mfa',
                        'label' => 'admin.hub.module.mfa.label',
                        'description' => 'admin.hub.module.mfa.desc',
                        'route' => 'admin_mfa_index',
                    ],
                ],
            ],
            [
                'key' => 'isms_data',
                'tone' => 'cyan',
                'icon' => 'fa-icon--nav-shield-check',
                'label' => 'admin.hub.group.isms_data.label',
                'description' => 'admin.hub.group.isms_data.desc',
                'modules' => [
                    [
                        'key' => 'frameworks',
                        'icon' => 'fa-icon--nav-collection',
                        'label' => 'admin.hub.module.frameworks.label',
                        'description' => 'admin.hub.module.frameworks.desc',
                        'route' => 'admin_compliance_index',
                    ],
                    [
                        'key' => 'mappings',
                        'icon' => 'fa-icon--nav-link',
                        'label' => 'admin.hub.module.mappings.label',
                        'description' => 'admin.hub.module.mappings.desc',
                        'route' => 'app_compliance_mapping_index',
                    ],
                    [
                        'key' => 'mapping_quality',
                        'icon' => 'fa-icon--nav-analytics',
                        'label' => 'admin.hub.module.mapping_quality.label',
                        'description' => 'admin.hub.module.mapping_quality.desc',
                        'route' => 'admin_mapping_quality_index',
                    ],
                    [
                        'key' => 'industry_baselines',
                        'icon' => 'fa-icon--nav-boxes',
                        'label' => 'admin.hub.module.industry_baselines.label',
                        'description' => 'admin.hub.module.industry_baselines.desc',
                        'route' => 'admin_industry_baselines_index',
                    ],
                    [
                        'key' => 'tags',
                        'icon' => 'fa-icon--nav-tags',
                        'label' => 'admin.hub.module.tags.label',
                        'description' => 'admin.hub.module.tags.desc',
                        'route' => 'admin_tag_index',
                    ],
                ],
            ],
            [
                'key' => 'audit_compliance',
                'tone' => 'purple',
                'icon' => 'fa-icon--nav-journal',
                'label' => 'admin.hub.group.audit_compliance.label',
                'description' => 'admin.hub.group.audit_compliance.desc',
                'modules' => [
                    [
                        'key' => 'audit_log',
                        'icon' => 'fa-icon--audit-trail',
                        'label' => 'admin.hub.module.audit_log.label',
                        'description' => 'admin.hub.module.audit_log.desc',
                        'route' => 'app_audit_log_index',
                    ],
                    [
                        'key' => 'audit_retention',
                        'icon' => 'fa-icon--archive',
                        'label' => 'admin.hub.module.audit_retention.label',
                        'description' => 'admin.hub.module.audit_retention.desc',
                        'route' => 'app_admin_audit_retention',
                    ],
                    [
                        'key' => 'compliance_policy',
                        'icon' => 'fa-icon--nav-file-earmark-text',
                        'label' => 'admin.hub.module.compliance_policy.label',
                        'description' => 'admin.hub.module.compliance_policy.desc',
                        'route' => 'admin_compliance_policy_index',
                    ],
                    [
                        'key' => 'risk_approval_config',
                        'icon' => 'fa-icon--ui-check',
                        'label' => 'admin.hub.module.risk_approval_config.label',
                        'description' => 'admin.hub.module.risk_approval_config.desc',
                        'route' => 'app_admin_risk_approval_config',
                    ],
                    [
                        'key' => 'incident_sla',
                        'icon' => 'fa-icon--nav-speedometer',
                        'label' => 'admin.hub.module.incident_sla.label',
                        'description' => 'admin.hub.module.incident_sla.desc',
                        'route' => 'app_admin_incident_sla_config',
                    ],
                    [
                        'key' => 'audit_log_monitoring',
                        'icon' => 'fa-icon--nav-activity',
                        'label' => 'admin.hub.module.audit_log_monitoring.label',
                        'description' => 'admin.hub.module.audit_log_monitoring.desc',
                        'route' => 'monitoring_audit_log',
                    ],
                    [
                        'key' => 'workflow_definitions',
                        'icon' => 'fa-icon--nav-workflow',
                        'label' => 'admin.hub.module.workflow_definitions.label',
                        'description' => 'admin.hub.module.workflow_definitions.desc',
                        'route' => 'app_workflow_definitions',
                    ],
                    [
                        'key' => 'scheduled_reports',
                        'icon' => 'fa-icon--ui-calendar-event',
                        'label' => 'admin.hub.module.scheduled_reports.label',
                        'description' => 'admin.hub.module.scheduled_reports.desc',
                        'route' => 'app_scheduled_report_index',
                    ],
                    // Sprint 8 — EU Authority reporting
                    [
                        'key' => 'authority_hub',
                        'icon' => 'fa-icon--regulator',
                        'label' => 'admin.hub.module.authority_hub.label',
                        'description' => 'admin.hub.module.authority_hub.desc',
                        'route' => 'authority_hub_index',
                        'requiredModule' => 'eu_authority_reporting',
                    ],
                    [
                        'key' => 'nis2_registration',
                        'icon' => 'fa-icon--nav-shield-alert',
                        'label' => 'admin.hub.module.nis2_registration.label',
                        'description' => 'admin.hub.module.nis2_registration.desc',
                        'route' => 'nis2_registration_index',
                        'requiredModule' => 'nis2_dora',
                    ],
                    [
                        'key' => 'dora_roi',
                        'icon' => 'fa-icon--nav-file-earmark-spreadsheet',
                        'label' => 'admin.hub.module.dora_roi.label',
                        'description' => 'admin.hub.module.dora_roi.desc',
                        'route' => 'dora_roi_index',
                        'requiredModule' => 'nis2_dora',
                    ],
                ],
            ],
            // Sprint 6b — Notifications: Rules, Channels, Templates, Preferences
            [
                'key' => 'notifications',
                'tone' => 'pink',
                'icon' => 'fa-icon--nav-bell',
                'label' => 'admin.hub.group.notifications.label',
                'description' => 'admin.hub.group.notifications.desc',
                'modules' => [
                    [
                        'key' => 'notification_rules',
                        'icon' => 'fa-icon--filter',
                        'label' => 'admin.hub.module.notification_rules.label',
                        'description' => 'admin.hub.module.notification_rules.desc',
                        'route' => 'admin_notification_rule_index',
                    ],
                    [
                        'key' => 'notification_channels',
                        'icon' => 'fa-icon--send',
                        'label' => 'admin.hub.module.notification_channels.label',
                        'description' => 'admin.hub.module.notification_channels.desc',
                        'route' => 'admin_notification_channel_index',
                    ],
                    [
                        'key' => 'notification_templates',
                        'icon' => 'fa-icon--nav-collection',
                        'label' => 'admin.hub.module.notification_templates.label',
                        'description' => 'admin.hub.module.notification_templates.desc',
                        'route' => 'admin_notification_template_index',
                    ],
                    [
                        'key' => 'notification_preferences',
                        'icon' => 'fa-icon--nav-sliders',
                        'label' => 'admin.hub.module.notification_preferences.label',
                        'description' => 'admin.hub.module.notification_preferences.desc',
                        'route' => 'admin_settings_notifications',
                    ],
                ],
            ],
            [
                'key' => 'integrations',
                'tone' => 'cyan',
                'icon' => 'fa-icon--nav-link',
                'label' => 'admin.hub.group.integrations.label',
                'description' => 'admin.hub.group.integrations.desc',
                'modules' => [
                    [
                        'key' => 'compliance_import',
                        'icon' => 'fa-icon--nav-upload',
                        'label' => 'admin.hub.module.compliance_import.label',
                        'description' => 'admin.hub.module.compliance_import.desc',
                        'route' => 'admin_compliance_import_upload',
                    ],
                    [
                        'key' => 'gstool_import',
                        'icon' => 'fa-icon--nav-download',
                        'label' => 'admin.hub.module.gstool_import.label',
                        'description' => 'admin.hub.module.gstool_import.desc',
                        'route' => 'admin_gstool_import_index',
                    ],
                    [
                        'key' => 'sample_data',
                        'icon' => 'fa-icon--nav-database-add',
                        'label' => 'admin.hub.module.sample_data.label',
                        'description' => 'admin.hub.module.sample_data.desc',
                        'route' => 'admin_sample_data_index',
                    ],
                    [
                        'key' => 'import_history',
                        'icon' => 'fa-icon--nav-clock-history',
                        'label' => 'admin.hub.module.import_history.label',
                        'description' => 'admin.hub.module.import_history.desc',
                        'route' => 'admin_import_history_index',
                    ],
                ],
            ],
            [
                'key' => 'system',
                'tone' => 'purple',
                'icon' => 'fa-icon--nav-cpu',
                'label' => 'admin.hub.group.system.label',
                'description' => 'admin.hub.group.system.desc',
                'modules' => [
                    [
                        'key' => 'system_health',
                        'icon' => 'fa-icon--nav-heart-pulse',
                        'label' => 'admin.hub.module.system_health.label',
                        'description' => 'admin.hub.module.system_health.desc',
                        'route' => 'monitoring_health',
                    ],
                    [
                        'key' => 'modules',
                        'icon' => 'fa-icon--nav-puzzle',
                        'label' => 'admin.hub.module.modules.label',
                        'description' => 'admin.hub.module.modules.desc',
                        'route' => 'admin_modules_index',
                    ],
                    [
                        'key' => 'data_repair',
                        'icon' => 'fa-icon--nav-wrench',
                        'label' => 'admin.hub.module.data_repair.label',
                        'description' => 'admin.hub.module.data_repair.desc',
                        'route' => 'admin_data_repair_index',
                    ],
                    [
                        'key' => 'quick_fix_settings',
                        'icon' => 'fa-icon--ui-launch',
                        'label' => 'admin.hub.module.quick_fix_settings.label',
                        'description' => 'admin.hub.module.quick_fix_settings.desc',
                        'route' => 'app_admin_quick_fix_settings',
                    ],
                    [
                        'key' => 'kpi_threshold',
                        'icon' => 'fa-icon--nav-sliders',
                        'label' => 'admin.hub.module.kpi_threshold.label',
                        'description' => 'admin.hub.module.kpi_threshold.desc',
                        'route' => 'admin_kpi_threshold_index',
                    ],
                    // Sprint 9A — F11 FTE-Tracking
                    [
                        'key' => 'fte_tracking',
                        'icon' => 'fa-icon--nav-clock-history',
                        'label' => 'admin.hub.module.fte_tracking.label',
                        'description' => 'admin.hub.module.fte_tracking.desc',
                        'route' => 'analytics_fte_index',
                        'requiredModule' => 'analytics',
                    ],
                    [
                        'key' => 'loader_fixer',
                        'icon' => 'fa-icon--nav-tools',
                        'label' => 'admin.hub.module.loader_fixer.label',
                        'description' => 'admin.hub.module.loader_fixer.desc',
                        'route' => 'admin_loader_fixer_index',
                    ],
                    [
                        'key' => 'monitoring_performance',
                        'icon' => 'fa-icon--nav-speedometer',
                        'label' => 'admin.hub.module.monitoring_performance.label',
                        'description' => 'admin.hub.module.monitoring_performance.desc',
                        'route' => 'monitoring_performance',
                    ],
                    [
                        'key' => 'licensing',
                        'icon' => 'fa-icon--nav-patch-check',
                        'label' => 'admin.hub.module.licensing.label',
                        'description' => 'admin.hub.module.licensing.desc',
                        'route' => 'admin_licensing_index',
                    ],
                    [
                        'key' => 'application_settings',
                        'icon' => 'fa-icon--nav-gear',
                        'label' => 'admin.hub.module.application_settings.label',
                        'description' => 'admin.hub.module.application_settings.desc',
                        'route' => 'admin_settings_index',
                    ],
                    // Sprint 6 deferred system-settings sub-pages
                    [
                        'key' => 'api_rate_limits',
                        'icon' => 'fa-icon--nav-speedometer',
                        'label' => 'admin.hub.module.api_rate_limits.label',
                        'description' => 'admin.hub.module.api_rate_limits.desc',
                        'route' => 'admin_settings_api_rate_limits',
                    ],
                    [
                        'key' => 'backup_settings',
                        'icon' => 'fa-icon--nav-database',
                        'label' => 'admin.hub.module.backup_settings.label',
                        'description' => 'admin.hub.module.backup_settings.desc',
                        'route' => 'admin_settings_backups',
                    ],
                    [
                        'key' => 'fiscal_year',
                        'icon' => 'fa-icon--nav-calendar',
                        'label' => 'admin.hub.module.fiscal_year.label',
                        'description' => 'admin.hub.module.fiscal_year.desc',
                        'route' => 'admin_settings_fiscal_year',
                    ],
                    [
                        'key' => 'data_retention_settings',
                        'icon' => 'fa-icon--nav-archive',
                        'label' => 'admin.hub.module.data_retention_settings.label',
                        'description' => 'admin.hub.module.data_retention_settings.desc',
                        'route' => 'admin_settings_data_retention',
                    ],
                    [
                        'key' => 'workflow_sla_defaults',
                        'icon' => 'fa-icon--ui-hourglass',
                        'label' => 'admin.hub.module.workflow_sla_defaults.label',
                        'description' => 'admin.hub.module.workflow_sla_defaults.desc',
                        'route' => 'admin_settings_workflow_slas',
                    ],
                    [
                        'key' => 'data_backup',
                        'icon' => 'fa-icon--nav-hdd',
                        'label' => 'admin.hub.module.data_backup.label',
                        'description' => 'admin.hub.module.data_backup.desc',
                        'route' => 'data_backup_index',
                    ],
                    [
                        'key' => 'monitoring_errors',
                        'icon' => 'fa-icon--nav-exclamation-triangle',
                        'label' => 'admin.hub.module.monitoring_errors.label',
                        'description' => 'admin.hub.module.monitoring_errors.desc',
                        'route' => 'monitoring_errors',
                    ],
                    [
                        'key' => 'api_doc',
                        'icon' => 'fa-icon--nav-journal-text',
                        'label' => 'admin.hub.module.api_doc.label',
                        'description' => 'admin.hub.module.api_doc.desc',
                        'route' => 'api_doc',
                    ],
                    [
                        'key' => 'setup_wizard',
                        'icon' => 'fa-icon--nav-magic',
                        'label' => 'admin.hub.module.setup_wizard.label',
                        'description' => 'admin.hub.module.setup_wizard.desc',
                        'route' => 'setup_wizard_index',
                    ],
                ],
            ],
            [
                'key' => 'branding_ux',
                'tone' => 'pink',
                'icon' => 'fa-icon--nav-palette',
                'label' => 'admin.hub.group.branding_ux.label',
                'description' => 'admin.hub.group.branding_ux.desc',
                'modules' => [
                    [
                        'key' => 'supplier_criticality',
                        'icon' => 'fa-icon--nav-speedometer',
                        'label' => 'admin.hub.module.supplier_criticality.label',
                        'description' => 'admin.hub.module.supplier_criticality.desc',
                        'route' => 'app_admin_supplier_criticality_index',
                    ],
                    [
                        'key' => 'tour_content',
                        'icon' => 'fa-icon--nav-book',
                        'label' => 'admin.hub.module.tour_content.label',
                        'description' => 'admin.hub.module.tour_content.desc',
                        'route' => 'admin_tour_content_index',
                    ],
                    [
                        'key' => 'tour_completion',
                        'icon' => 'fa-icon--nav-mortarboard',
                        'label' => 'admin.hub.module.tour_completion.label',
                        'description' => 'admin.hub.module.tour_completion.desc',
                        'route' => 'admin_tour_completion_index',
                    ],
                    [
                        'key' => 'alva_hint_stats',
                        'icon' => 'fa-icon--nav-stars',
                        'label' => 'admin.hub.module.alva_hint_stats.label',
                        'description' => 'admin.hub.module.alva_hint_stats.desc',
                        'route' => 'app_alva_hint_stats',
                    ],
                ],
            ],
        ];
    }
}
