<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Service\ModuleConfigurationService;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Global-search provider for navigation targets (settings, admin pages,
 * dashboards): a static catalogue of routes with translated labels and
 * descriptions (domain `search_nav`) plus DE/EN aliases, filtered by the
 * current user's roles (the same attributes the controllers check) and the
 * tenant's active modules. A query matches the label in the UI language and
 * in German, so a German term finds the page in an English UI too.
 *
 * Extracted from {@see SearchService} (god-class ratchet, Gate 25).
 */
class NavigationSearch
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AuthorizationCheckerInterface $authChecker,
        private readonly ModuleConfigurationService $moduleConfig,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * Search navigation targets (settings, admin pages, dashboards).
     * Returns matches first in result order. Role-filtered per current user.
     * Each entry may carry an optional `aliases` array for synonym/keyword matching.
     */
    public function search(string $query): array
    {
        $navMap = [
            // ── Core / Admin ──────────────────────────────────────────────────
            [
                'route' => 'admin_dashboard',
                'label' => 'search_nav.admin_dashboard.label',
                'description' => 'search_nav.admin_dashboard.description',
                'aliases' => ['Admin', 'Verwaltung', 'System'],
                'icon' => 'fa-icon--nav-sliders',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'admin_hub_index',
                'label' => 'search_nav.admin_hub_index.label',
                'description' => 'search_nav.admin_hub_index.description',
                'aliases' => ['Admin', 'Verwaltung', 'Settings', 'Einstellungen'],
                'icon' => 'fa-icon--nav-sliders',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── Organisation / Tenant ─────────────────────────────────────────
            [
                'route' => 'tenant_management_index',
                'label' => 'search_nav.tenant_management_index.label',
                'description' => 'search_nav.tenant_management_index.description',
                'aliases' => ['Organisation', 'Organization', 'Tenant', 'Mandant', 'Firma', 'Company', 'Einstellungen', 'Settings', 'Konfiguration', 'Config'],
                'icon' => 'fa-icon--nav-sliders',
                'roles' => ['TENANT_VIEW'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_admin_tenant_email_branding',
                'label' => 'search_nav.app_admin_tenant_email_branding.label',
                'description' => 'search_nav.app_admin_tenant_email_branding.description',
                'aliases' => ['Logo', 'Branding', 'Theme', 'Farben', 'Design', 'CI', 'Corporate Identity', 'E-Mail-Design', 'Email', 'Erscheinungsbild'],
                'icon' => 'fa-icon--nav-sliders',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── User & Role management ─────────────────────────────────────────
            [
                'route' => 'user_management_index',
                'label' => 'search_nav.user_management_index.label',
                'description' => 'search_nav.user_management_index.description',
                'aliases' => ['Benutzer', 'User', 'Konto', 'Account', 'Mitarbeiter', 'Nutzer', 'Passwort', 'Password'],
                'icon' => 'fa-icon--nav-users',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'role_management_index',
                'label' => 'search_nav.role_management_index.label',
                'description' => 'search_nav.role_management_index.description',
                'aliases' => ['Rollen', 'Berechtigung', 'Berechtigungen', 'Permission', 'Permissions', 'RBAC', 'Zugriff', 'Access', 'Rechte', 'Zugriffssteuerung'],
                'icon' => 'fa-icon--nav-shield',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── Profile / reminders ───────────────────────────────────────────
            [
                'route' => 'app_profile',
                'label' => 'search_nav.app_profile.label',
                'description' => 'search_nav.app_profile.description',
                'aliases' => ['Profil', 'Profile', 'Erinnerungen', 'Reminders', 'Sammelmail', 'Digest', 'Konto', 'Account'],
                'icon' => 'fa-icon--nav-users',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_notification_center',
                'label' => 'search_nav.app_notification_center.label',
                'description' => 'search_nav.app_notification_center.description',
                'aliases' => ['Benachrichtigungen', 'Notifications', 'Glocke', 'Bell', 'Erinnerungen', 'Reminders', 'Meldungen'],
                'icon' => 'fa-icon--nav-bell',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            // ── MFA / Session ─────────────────────────────────────────────────
            [
                'route' => 'app_profile_mfa_index',
                'label' => 'search_nav.app_profile_mfa_index.label',
                'description' => 'search_nav.app_profile_mfa_index.description',
                'aliases' => ['MFA', '2FA', 'Zwei-Faktor', 'Two-Factor', 'TOTP', 'Authenticator', 'Multi-Faktor', 'Sicherheitsschlüssel'],
                'icon' => 'fa-icon--nav-shield',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'session_index',
                'label' => 'search_nav.session_index.label',
                'description' => 'search_nav.session_index.description',
                'aliases' => ['Sitzung', 'Session', 'Aktive Logins', 'Login', 'Anmeldung', 'Geräte'],
                'icon' => 'fa-icon--nav-shield',
                'roles' => ['SESSION_VIEW'],
                'requiresModule' => null,
            ],
            // ── Audit / Compliance ────────────────────────────────────────────
            [
                'route' => 'app_audit_log_index',
                'label' => 'search_nav.app_audit_log_index.label',
                'description' => 'search_nav.app_audit_log_index.description',
                'aliases' => ['Audit-Log', 'Aktivität', 'Activity', 'Historie', 'History', 'Trail', 'Änderungsprotokoll', 'Log', 'Protokoll'],
                'icon' => 'fa-icon--nav-clipboard-check',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_audit_freeze_index',
                'label' => 'search_nav.app_audit_freeze_index.label',
                'description' => 'search_nav.app_audit_freeze_index.description',
                'aliases' => ['Audit-Freeze', 'Freeze', 'Einfrieren', 'Read-Only', 'Auditierung sperren', 'Zertifizierung', 'Snapshot'],
                'icon' => 'fa-icon--nav-clipboard-check',
                'roles' => ['ROLE_MANAGER'],
                'requiresModule' => null,
            ],
            // ── SoA / Controls ────────────────────────────────────────────────
            [
                'route' => 'app_soa_index',
                'label' => 'search_nav.app_soa_index.label',
                'description' => 'search_nav.app_soa_index.description',
                'aliases' => ['SoA', 'Anwendbarkeitserklärung', 'Annex A', 'Kontrollen', 'controls statement', 'Statement of Applicability'],
                'icon' => 'fa-icon--control',
                'roles' => ['ROLE_USER'],
                'requiresModule' => 'controls',
            ],
            // ── Norm registers (Paket 9) ──────────────────────────────────────
            [
                'route' => 'app_register_index',
                'label' => 'search_nav.register_overview.label',
                'description' => 'search_nav.register_overview.description',
                'aliases' => ['Register', 'Verzeichnis', 'Registers', 'Kataster', 'Inventare', 'ISO 27001 4', 'Audit'],
                'icon' => 'fa-icon--nav-list-ordered',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_register_list',
                'params' => ['type' => 'issue_external'],
                'label' => 'search_nav.register_issues.label',
                'description' => 'search_nav.register_issues.description',
                'aliases' => ['Kontext', 'Themen', 'interne Themen', 'externe Themen', 'issues', 'PESTLE', '4.1'],
                'icon' => 'fa-icon--nav-process',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_register_list',
                'params' => ['type' => 'legal_requirement'],
                'label' => 'search_nav.register_legal.label',
                'description' => 'search_nav.register_legal.description',
                'aliases' => ['Rechtskataster', 'Gesetze', 'Verträge', 'Compliance', 'legal register', 'A.5.31'],
                'icon' => 'fa-icon--compliance-shield',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_register_list',
                'params' => ['type' => 'role'],
                'label' => 'search_nav.register_roles.label',
                'description' => 'search_nav.register_roles.description',
                'aliases' => ['Rollen', 'Verantwortlichkeiten', 'Ernennung', 'ISB', 'roles', 'RACI', '5.3'],
                'icon' => 'fa-icon--nav-users',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_register_list',
                'params' => ['type' => 'competence'],
                'label' => 'search_nav.register_competences.label',
                'description' => 'search_nav.register_competences.description',
                'aliases' => ['Kompetenz', 'Qualifikation', 'Kompetenzlücken', 'competence', 'skills', '7.2'],
                'icon' => 'fa-icon--nav-stars',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_register_list',
                'params' => ['type' => 'communication'],
                'label' => 'search_nav.register_communication.label',
                'description' => 'search_nav.register_communication.description',
                'aliases' => ['Kommunikation', 'Kommunikationsplan', 'communication plan', '7.4'],
                'icon' => 'fa-icon--nav-chat-quote',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_register_list',
                'params' => ['type' => 'opportunity'],
                'label' => 'search_nav.register_opportunities.label',
                'description' => 'search_nav.register_opportunities.description',
                'aliases' => ['Chancen', 'Verbesserung', 'opportunities', '6.1.1'],
                'icon' => 'fa-icon--ui-lightbulb',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_register_list',
                'params' => ['type' => 'scope_interface'],
                'label' => 'search_nav.register_interfaces.label',
                'description' => 'search_nav.register_interfaces.description',
                'aliases' => ['Schnittstellen', 'Geltungsbereich', 'Scope', 'interfaces', '4.3'],
                'icon' => 'fa-icon--nav-link',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            // ── Risk ──────────────────────────────────────────────────────────
            [
                'route' => 'app_risk_appetite_index',
                'label' => 'search_nav.app_risk_appetite_index.label',
                'description' => 'search_nav.app_risk_appetite_index.description',
                'aliases' => ['Risikoappetit', 'Risikotoleranz', 'Risk Appetite', 'Schwellenwert', 'Threshold'],
                'icon' => 'fa-icon--nav-shield-alert',
                'roles' => ['ROLE_USER'],
                'requiresModule' => 'risk_appetite',
            ],
            // ── Analytics / KPI ───────────────────────────────────────────────
            [
                'route' => 'app_analytics_dashboard',
                'label' => 'search_nav.app_analytics_dashboard.label',
                'description' => 'search_nav.app_analytics_dashboard.description',
                'aliases' => ['KPI', 'Kennzahl', 'Kennzahlen', 'Metrik', 'Metric', 'Diagramm', 'Chart', 'Trend', 'Statistik', 'Statistics'],
                'icon' => 'fa-icon--nav-bar-chart',
                'roles' => ['ROLE_MANAGER'],
                'requiresModule' => 'analytics',
            ],
            [
                'route' => 'admin_kpi_threshold_index',
                'label' => 'search_nav.admin_kpi_threshold_index.label',
                'description' => 'search_nav.admin_kpi_threshold_index.description',
                'aliases' => ['KPI', 'Kennzahl', 'Schwellenwert', 'Threshold', 'Zielwert', 'Grenzwert', 'Metrik'],
                'icon' => 'fa-icon--nav-bar-chart',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => 'analytics',
            ],
            // ── Reports ───────────────────────────────────────────────────────
            [
                'route' => 'app_reports_index',
                'label' => 'search_nav.app_reports_index.label',
                'description' => 'search_nav.app_reports_index.description',
                'aliases' => ['Reports', 'Bericht', 'Berichte', 'Auswertung', 'Auswertungen', 'Statistik', 'Export', 'PDF', 'Excel'],
                'icon' => 'fa-icon--nav-reports',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'report_builder_index',
                'label' => 'search_nav.report_builder_index.label',
                'description' => 'search_nav.report_builder_index.description',
                'aliases' => ['Report-Builder', 'Berichts-Designer', 'Custom Report', 'Bericht erstellen', 'Report Designer', 'Berichtsersteller'],
                'icon' => 'fa-icon--nav-reports',
                'roles' => ['ROLE_USER'],
                'requiresModule' => 'report_builder',
            ],
            [
                'route' => 'app_group_report_tree',
                'label' => 'search_nav.app_group_report_tree.label',
                'description' => 'search_nav.app_group_report_tree.description',
                'aliases' => ['Konzern', 'Portfolio', 'Holding', 'Gruppe', 'Group Report', 'Portfolio-Report', 'Konzernbericht', 'Multi-Tenant'],
                'icon' => 'fa-icon--nav-reports',
                'roles' => ['ROLE_GROUP_CISO', 'ROLE_KONZERN_AUDITOR'],
                'requiresModule' => 'portfolio_reports',
            ],
            [
                'route' => 'app_group_report_comparison',
                'label' => 'search_nav.app_group_report_comparison.label',
                'description' => 'search_nav.app_group_report_comparison.description',
                'aliases' => ['Vergleich', 'Mandanten-Vergleich', 'Mandantenvergleich', 'Benchmark', 'Töchter', 'Tochtergesellschaften', 'Comparison', 'KPI-Matrix'],
                'icon' => 'fa-icon--nav-reports',
                'roles' => ['ROLE_GROUP_CISO', 'ROLE_KONZERN_AUDITOR'],
                'requiresModule' => 'portfolio_reports',
            ],
            [
                'route' => 'admin_compliance_index',
                'label' => 'search_nav.admin_compliance_index.label',
                'description' => 'search_nav.admin_compliance_index.description',
                'aliases' => ['Gap', 'Lücke', 'Lücken', 'Lücken-Analyse', 'Gap Analysis', 'Gap-Report', 'Compliance Gap', 'offene Anforderungen', 'Compliance Hub'],
                'icon' => 'fa-icon--compliance-shield',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => 'compliance',
            ],
            [
                'route' => 'app_scheduled_report_index',
                'label' => 'search_nav.app_scheduled_report_index.label',
                'description' => 'search_nav.app_scheduled_report_index.description',
                'aliases' => ['Schedule', 'Scheduled', 'Geplante Berichte', 'Cron', 'Automatisch', 'Zeitplan', 'Recurring', 'Wiederkehrend'],
                'icon' => 'fa-icon--nav-reports',
                'roles' => ['Schedule', 'Scheduled', 'Geplante Berichte', 'Cron', 'Automatisch', 'Zeitplan', 'Recurring', 'Wiederkehrend'],
                'requiresModule' => 'scheduled_reports',
            ],
            // ── Backup / Restore ──────────────────────────────────────────────
            [
                'route' => 'data_backup_index',
                'label' => 'search_nav.data_backup_index.label',
                'description' => 'search_nav.data_backup_index.description',
                'aliases' => ['Backup', 'Sicherung', 'Datensicherung', 'Sichern', 'Export', 'Snapshot', 'Archiv', 'Restore', 'Wiederherstellung', 'Recovery', 'Wiederherstellen', 'Einspielen', 'Import'],
                'icon' => 'fa-icon--nav-wrench',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── Modules / Lifecycle / Workflow ────────────────────────────────
            [
                'route' => 'admin_modules_index',
                'label' => 'search_nav.admin_modules_index.label',
                'description' => 'search_nav.admin_modules_index.description',
                'aliases' => ['Module', 'Modul', 'Funktionen', 'Features', 'Aktivieren', 'Deaktivieren', 'Modul-Konfiguration', 'Lizenz'],
                'icon' => 'fa-icon--nav-grid',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'admin_lifecycle_overrides_index',
                'label' => 'search_nav.admin_lifecycle_overrides_index.label',
                'description' => 'search_nav.admin_lifecycle_overrides_index.description',
                'aliases' => ['Lifecycle', 'Workflow', 'Status-Übergang', 'Approval', 'Genehmigung', '4-Augen', 'Vier-Augen', 'Status'],
                'icon' => 'fa-icon--nav-workflow',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'admin_workflow_overlay_index',
                'label' => 'search_nav.admin_workflow_overlay_index.label',
                'description' => 'search_nav.admin_workflow_overlay_index.description',
                'aliases' => ['Workflow', 'Regulatorisch', 'Prozess', 'Process', 'Genehmigung', 'Approval'],
                'icon' => 'fa-icon--nav-workflow',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── Notifications ─────────────────────────────────────────────────
            [
                'route' => 'admin_notification_channel_index',
                'label' => 'search_nav.admin_notification_channel_index.label',
                'description' => 'search_nav.admin_notification_channel_index.description',
                'aliases' => ['Benachrichtigung', 'Notification', 'Slack', 'Email', 'E-Mail', 'Teams', 'Webhook', 'Channel', 'Kanal'],
                'icon' => 'fa-icon--nav-bell',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => 'notifications',
            ],
            [
                'route' => 'admin_notification_rule_index',
                'label' => 'search_nav.admin_notification_rule_index.label',
                'description' => 'search_nav.admin_notification_rule_index.description',
                'aliases' => ['Regel', 'Rule', 'Trigger', 'Bedingung', 'Alert', 'Alarm', 'Automatisch', 'Benachrichtigung'],
                'icon' => 'fa-icon--nav-bell',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => 'notifications',
            ],
            // ── Tags ──────────────────────────────────────────────────────────
            [
                'route' => 'admin_tag_index',
                'label' => 'search_nav.admin_tag_index.label',
                'description' => 'search_nav.admin_tag_index.description',
                'aliases' => ['Tags', 'Labels', 'Schlagwort', 'Schlagwörter', 'Markierung', 'Label', 'Tag'],
                'icon' => 'fa-icon--nav-grid',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── Dashboards / Personas ─────────────────────────────────────────
            [
                'route' => 'app_dashboard',
                'label' => 'search_nav.app_dashboard.label',
                'description' => 'search_nav.app_dashboard.description',
                'aliases' => ['Dashboard', 'Startseite', 'Übersicht', 'Home', 'Cockpit'],
                'icon' => 'fa-icon--nav-home',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_dashboard_ciso',
                'label' => 'search_nav.app_dashboard_ciso.label',
                'description' => 'search_nav.app_dashboard_ciso.description',
                'aliases' => ['CISO', 'Dashboard', 'Sicherheitslage', 'Security', 'Cockpit'],
                'icon' => 'fa-icon--nav-home',
                'roles' => ['PERSONA_CISO'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_dashboard_dpo',
                'label' => 'search_nav.app_dashboard_dpo.label',
                'description' => 'search_nav.app_dashboard_dpo.description',
                'aliases' => ['DPO', 'Datenschutz', 'Privacy', 'DSGVO', 'GDPR', 'Dashboard', 'Cockpit'],
                'icon' => 'fa-icon--nav-home',
                'roles' => ['PERSONA_DPO'],
                'requiresModule' => 'privacy',
            ],
            [
                'route' => 'app_dashboard_compliance_manager',
                'label' => 'search_nav.app_dashboard_compliance_manager.label',
                'description' => 'search_nav.app_dashboard_compliance_manager.description',
                'aliases' => ['Compliance Manager', 'CM', 'GRC', 'Framework', 'Dashboard', 'Cockpit', 'RM'],
                'icon' => 'fa-icon--nav-home',
                'roles' => ['PERSONA_COMPLIANCE'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_my_day',
                'label' => 'search_nav.app_my_day.label',
                'description' => 'search_nav.app_my_day.description',
                'aliases' => ['My Day', 'Posteingang', 'Inbox', 'Aufgaben', 'Tasks', 'Freigaben', 'Approvals', 'To-do'],
                'icon' => 'fa-icon--nav-dashboard',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_suggestion_index',
                'label' => 'search_nav.app_suggestion_index.label',
                'description' => 'search_nav.app_suggestion_index.description',
                'aliases' => ['Vorschläge', 'Suggestions', 'KI', 'AI', 'Vererbung', 'Inheritance', 'Ableitung', 'Queue'],
                'icon' => 'fa-icon--nav-magic',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'admin_settings_ai',
                'label' => 'search_nav.admin_settings_ai.label',
                'description' => 'search_nav.admin_settings_ai.description',
                'aliases' => ['KI', 'AI', 'Mistral', 'Ollama', 'LLM', 'Sprachmodell', 'Language model', 'API-Schlüssel'],
                'icon' => 'fa-icon--nav-magic',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_dashboard_risk_manager',
                'label' => 'search_nav.app_dashboard_risk_manager.label',
                'description' => 'search_nav.app_dashboard_risk_manager.description',
                'aliases' => ['Risk Manager', 'Risiko-Dashboard', 'Risk Dashboard', 'Risikomanagement'],
                'icon' => 'fa-icon--nav-dashboard',
                'roles' => ['PERSONA_RISK'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_dashboard_isb',
                'label' => 'search_nav.app_dashboard_isb.label',
                'description' => 'search_nav.app_dashboard_isb.description',
                'aliases' => ['ISB', 'ISO', 'Informationssicherheitsbeauftragter', 'Security Officer'],
                'icon' => 'fa-icon--nav-dashboard',
                'roles' => ['PERSONA_ISB'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_dashboard_bcm',
                'label' => 'search_nav.app_dashboard_bcm.label',
                'description' => 'search_nav.app_dashboard_bcm.description',
                'aliases' => ['BCM', 'Business Continuity', 'Notfallmanagement', 'BCM Officer'],
                'icon' => 'fa-icon--nav-dashboard',
                'roles' => ['PERSONA_BCM'],
                'requiresModule' => 'bcm',
            ],
            [
                'route' => 'app_dashboard_auditor',
                'label' => 'search_nav.app_dashboard_auditor.label',
                'description' => 'search_nav.app_dashboard_auditor.description',
                'aliases' => ['Auditor', 'Audit-Dashboard', 'Prüfer'],
                'icon' => 'fa-icon--nav-dashboard',
                'roles' => ['ROLE_AUDITOR'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_dashboard_board',
                'label' => 'search_nav.app_dashboard_board.label',
                'description' => 'search_nav.app_dashboard_board.description',
                'aliases' => ['Board', 'Vorstand', 'Geschäftsführung', 'Leitung', 'Top Management', 'Management', 'Executive'],
                'icon' => 'fa-icon--nav-dashboard',
                'roles' => ['PERSONA_BOARD'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_certification_bundle_index',
                'label' => 'search_nav.app_certification_bundle_index.label',
                'description' => 'search_nav.app_certification_bundle_index.description',
                'aliases' => ['Audit-Paket', 'Audit Package', 'Zertifizierungsbündel', 'Certification Bundle', 'Nachweise', 'Evidence', 'ZIP'],
                'icon' => 'fa-icon--nav-download',
                'roles' => ['REPORT_CERTIFICATION_BUNDLE'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_compliance_mapping_hub',
                'label' => 'search_nav.app_compliance_mapping_hub.label',
                'description' => 'search_nav.app_compliance_mapping_hub.description',
                'aliases' => ['Mapping', 'Mappings', 'Cross-Framework', 'Zuordnung', 'Grundschutz++', 'Vorschläge'],
                'icon' => 'fa-icon--compliance-shield',
                'roles' => ['ROLE_USER'],
                'requiresModule' => 'compliance',
            ],
            // ── Onboarding / Setup ────────────────────────────────────────────
            [
                'route' => 'setup_wizard_index',
                'label' => 'search_nav.setup_wizard_index.label',
                'description' => 'search_nav.setup_wizard_index.description',
                'aliases' => ['Setup', 'Onboarding', 'Wizard', 'Erste Schritte', 'Getting Started', 'Einrichten', 'Einrichtung'],
                'icon' => 'fa-icon--nav-stars',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── Glossary ──────────────────────────────────────────────────────
            [
                'route' => 'app_help_glossary',
                'label' => 'search_nav.app_help_glossary.label',
                'description' => 'search_nav.app_help_glossary.description',
                'aliases' => ['Glossary', 'Begriff', 'Begriffe', 'Definition', 'Akronym', 'Akronyme', 'Lexikon'],
                'icon' => 'fa-icon--nav-book',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            // ── Maintenance / DevOps ──────────────────────────────────────────
            [
                'route' => 'app_quick_fix_index',
                'label' => 'search_nav.app_quick_fix_index.label',
                'description' => 'search_nav.app_quick_fix_index.description',
                'aliases' => ['Quick-Fix', 'Reparatur', 'Repair', 'Schema', 'Drift', 'Migration', 'Migrations'],
                'icon' => 'fa-icon--nav-wrench',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'admin_data_repair_index',
                'label' => 'search_nav.admin_data_repair_index.label',
                'description' => 'search_nav.admin_data_repair_index.description',
                'aliases' => ['Daten-Reparatur', 'Data-Repair', 'Orphan', 'Verwaiste Daten', 'Duplikate', 'Bereinigung', 'Repair'],
                'icon' => 'fa-icon--nav-wrench',
                'roles' => ['ADMIN_GLOBAL_OP'],
                'requiresModule' => null,
            ],
            [
                'route' => 'admin_legacy_owner_index',
                'label' => 'search_nav.admin_legacy_owner_index.label',
                'description' => 'search_nav.admin_legacy_owner_index.description',
                'aliases' => ['Freitext', 'Legacy', 'Eigentümer', 'Owner', 'Verantwortliche', 'Ansprechpartner', 'Zuordnen'],
                'icon' => 'fa-icon--ui-person',
                'roles' => ['ADMIN_OWN_TENANT'],
                'requiresModule' => null,
            ],
            [
                'route' => 'admin_queue_status',
                'label' => 'search_nav.admin_queue_status.label',
                'description' => 'search_nav.admin_queue_status.description',
                'aliases' => ['Queue', 'Worker', 'Messenger', 'Async-Job', 'Hintergrund', 'Background', 'Job', 'Jobs'],
                'icon' => 'fa-icon--nav-clock',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── Industry Baseline ─────────────────────────────────────────────
            [
                'route' => 'app_industry_baseline_index',
                'label' => 'search_nav.app_industry_baseline_index.label',
                'description' => 'search_nav.app_industry_baseline_index.description',
                'aliases' => ['Industry', 'Branche', 'Sektor', 'Baseline', 'BSI-Bausteine', 'Branchen-Baseline', 'Industriestandard', 'Sector'],
                'icon' => 'fa-icon--nav-grid',
                'roles' => ['ROLE_MANAGER'],
                'requiresModule' => null,
            ],
            // ── Locations ─────────────────────────────────────────────────────
            [
                'route' => 'app_location_index',
                'label' => 'search_nav.app_location_index.label',
                'description' => 'search_nav.app_location_index.description',
                'aliases' => ['Standort', 'Standorte', 'Location', 'Site', 'Niederlassung', 'Büro', 'Office', 'Filiale'],
                'icon' => 'fa-icon--nav-location',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_location_map',
                'label' => 'search_nav.app_location_map.label',
                'description' => 'search_nav.app_location_map.description',
                'aliases' => ['Karte', 'Weltkarte', 'Standortkarte', 'Lagebild', 'Map', 'Location map', 'Site map'],
                'icon' => 'fa-icon--nav-location',
                'roles' => ['ROLE_USER'],
                'requiresModule' => 'locations',
            ],
            // ── API Documentation ─────────────────────────────────────────────
            [
                'route' => 'api_doc',
                'label' => 'search_nav.api_doc.label',
                'description' => 'search_nav.api_doc.description',
                'aliases' => ['API', 'REST', 'JSON', 'Doku', 'Documentation', 'OpenAPI', 'Swagger', 'Schnittstelle', 'Integration'],
                'icon' => 'fa-icon--nav-book',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
                'params' => ['_format' => 'html'],
            ],
            // ── Compliance Hub ────────────────────────────────────────────────
            [
                'route' => 'admin_tenant_compliance_settings_current',
                'label' => 'search_nav.admin_tenant_compliance_settings_current.label',
                'description' => 'search_nav.admin_tenant_compliance_settings_current.description',
                'aliases' => ['Compliance', 'Frameworks', 'ISO', 'ISO 27001', 'TISAX', 'DORA', 'NIS2', 'BSI', 'Framework', 'Normen', 'Standards'],
                'icon' => 'fa-icon--compliance-shield',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
        ];

        $results = [];
        foreach ($navMap as $item) {
            // Module check — skip if required module is inactive
            if ($item['requiresModule'] !== null && !$this->moduleConfig->isModuleActive($item['requiresModule'])) {
                continue;
            }

            // Role check — skip if user lacks all required roles
            $hasRole = false;
            foreach ($item['roles'] as $role) {
                if ($this->authChecker->isGranted($role)) {
                    $hasRole = true;
                    break;
                }
            }
            if (!$hasRole) {
                continue;
            }

            [$labelKey, $descriptionKey] = [$item['label'], $item['description']];
            $item['label'] = $this->translator->trans($labelKey, [], 'search_nav');
            $item['description'] = $this->translator->trans($descriptionKey, [], 'search_nav');
            $item['aliases'][] = $this->translator->trans($labelKey, [], 'search_nav', 'de');
            $item['aliases'][] = $this->translator->trans($descriptionKey, [], 'search_nav', 'de');

            if ($this->navigationEntryMatches($item, $query)) {
                try {
                    $url = $this->urlGenerator->generate($item['route'], $item['params'] ?? []);
                } catch (MissingMandatoryParametersException) {
                    continue;
                }
                $results[] = [
                    'title' => $item['label'],
                    'description' => $item['description'],
                    'url' => $url,
                    'icon' => $item['icon'],
                    'type' => 'navigation',
                ];
            }
        }

        return $results;
    }

    /**
     * Case-insensitive substring match against label, description, and any aliases.
     * Queries shorter than 2 characters are always rejected.
     */
    private function navigationEntryMatches(array $entry, string $query): bool
    {
        $needle = mb_strtolower(trim($query));
        if (mb_strlen($needle) < 2) {
            return false;
        }

        $haystack = [
            $entry['label'] ?? '',
            $entry['description'] ?? '',
            ...($entry['aliases'] ?? []),
        ];

        foreach ($haystack as $field) {
            if (str_contains(mb_strtolower((string) $field), $needle)) {
                return true;
            }
        }

        return false;
    }
}
