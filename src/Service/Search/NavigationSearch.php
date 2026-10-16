<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Service\ModuleConfigurationService;
use Symfony\Component\Routing\Exception\MissingMandatoryParametersException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Global-search provider for navigation targets (settings, admin pages,
 * dashboards): a static catalogue of routes with labels, descriptions and
 * DE/EN aliases, filtered by the current user's roles and the tenant's active
 * modules.
 *
 * Extracted from {@see SearchService} (god-class ratchet, Gate 25).
 */
class NavigationSearch
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AuthorizationCheckerInterface $authChecker,
        private readonly ModuleConfigurationService $moduleConfig,
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
                'label' => 'Admin Dashboard',
                'description' => 'Tenant- und Benutzerverwaltung, System-Status',
                'aliases' => ['Admin', 'Verwaltung', 'System'],
                'icon' => 'fa-icon--nav-sliders',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'admin_hub_index',
                'label' => 'Admin-Hub',
                'description' => 'Zentraler Einstieg für alle Admin-Funktionen',
                'aliases' => ['Admin', 'Verwaltung', 'Settings', 'Einstellungen'],
                'icon' => 'fa-icon--nav-sliders',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── Organisation / Tenant ─────────────────────────────────────────
            [
                'route' => 'tenant_management_index',
                'label' => 'Organisations-Einstellungen',
                'description' => 'Organisationen verwalten: Name, Kontakt, ISMS-Scope',
                'aliases' => ['Organisation', 'Organization', 'Tenant', 'Mandant', 'Firma', 'Company', 'Einstellungen', 'Settings', 'Konfiguration', 'Config'],
                'icon' => 'fa-icon--nav-sliders',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_admin_tenant_email_branding',
                'label' => 'Branding & Logo',
                'description' => 'Logo, Farben, CI, E-Mail-Design anpassen',
                'aliases' => ['Logo', 'Branding', 'Theme', 'Farben', 'Design', 'CI', 'Corporate Identity', 'E-Mail-Design', 'Email', 'Erscheinungsbild'],
                'icon' => 'fa-icon--nav-sliders',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── User & Role management ─────────────────────────────────────────
            [
                'route' => 'user_management_index',
                'label' => 'Benutzerverwaltung',
                'description' => 'Benutzer anlegen, Rollen vergeben, Passwörter zurücksetzen',
                'aliases' => ['Benutzer', 'User', 'Konto', 'Account', 'Mitarbeiter', 'Nutzer', 'Passwort', 'Password'],
                'icon' => 'fa-icon--nav-users',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'role_management_index',
                'label' => 'Rollenverwaltung',
                'description' => 'Rollen und Berechtigungen konfigurieren',
                'aliases' => ['Rollen', 'Berechtigung', 'Berechtigungen', 'Permission', 'Permissions', 'RBAC', 'Zugriff', 'Access', 'Rechte', 'Zugriffssteuerung'],
                'icon' => 'fa-icon--nav-shield',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── MFA / Session ─────────────────────────────────────────────────
            [
                'route' => 'app_profile_mfa_index',
                'label' => 'MFA / Zwei-Faktor-Authentifizierung',
                'description' => 'TOTP-Authenticator einrichten oder deaktivieren',
                'aliases' => ['MFA', '2FA', 'Zwei-Faktor', 'Two-Factor', 'TOTP', 'Authenticator', 'Multi-Faktor', 'Sicherheitsschlüssel'],
                'icon' => 'fa-icon--nav-shield',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'session_index',
                'label' => 'Sitzungsverwaltung',
                'description' => 'Aktive Logins anzeigen und beenden',
                'aliases' => ['Sitzung', 'Session', 'Aktive Logins', 'Login', 'Anmeldung', 'Geräte'],
                'icon' => 'fa-icon--nav-shield',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            // ── Audit / Compliance ────────────────────────────────────────────
            [
                'route' => 'app_audit_log_index',
                'label' => 'Audit-Log',
                'description' => 'Compliance-Audittrail, alle Änderungen lückenlos',
                'aliases' => ['Audit-Log', 'Aktivität', 'Activity', 'Historie', 'History', 'Trail', 'Änderungsprotokoll', 'Log', 'Protokoll'],
                'icon' => 'fa-icon--nav-clipboard-check',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_audit_freeze_index',
                'label' => 'Audit-Freeze',
                'description' => 'ISMS-Status einfrieren für externe Audits (Read-Only-Modus)',
                'aliases' => ['Audit-Freeze', 'Freeze', 'Einfrieren', 'Read-Only', 'Auditierung sperren', 'Zertifizierung', 'Snapshot'],
                'icon' => 'fa-icon--nav-clipboard-check',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── SoA / Controls ────────────────────────────────────────────────
            [
                'route' => 'app_soa_index',
                'label' => 'SoA – Statement of Applicability',
                'description' => 'ISO 27001 Annex-A Controls, Anwendbarkeitserklärung',
                'aliases' => ['SoA', 'Anwendbarkeitserklärung', 'Annex A', 'Kontrollen', 'controls statement', 'Statement of Applicability'],
                'icon' => 'fa-icon--control',
                'roles' => ['ROLE_USER'],
                'requiresModule' => 'controls',
            ],
            // ── Risk ──────────────────────────────────────────────────────────
            [
                'route' => 'app_risk_appetite_index',
                'label' => 'Risikoappetit',
                'description' => 'Risikotoleranz und Schwellenwerte konfigurieren',
                'aliases' => ['Risikoappetit', 'Risikotoleranz', 'Risk Appetite', 'Schwellenwert', 'Threshold'],
                'icon' => 'fa-icon--nav-shield-alert',
                'roles' => ['ROLE_MANAGER'],
                'requiresModule' => 'risk_appetite',
            ],
            // ── Analytics / KPI ───────────────────────────────────────────────
            [
                'route' => 'app_analytics_dashboard',
                'label' => 'Analytics',
                'description' => 'KPI-Übersicht, Diagramme, Trend-Auswertungen',
                'aliases' => ['KPI', 'Kennzahl', 'Kennzahlen', 'Metrik', 'Metric', 'Diagramm', 'Chart', 'Trend', 'Statistik', 'Statistics'],
                'icon' => 'fa-icon--nav-bar-chart',
                'roles' => ['ROLE_MANAGER'],
                'requiresModule' => 'analytics',
            ],
            [
                'route' => 'admin_kpi_threshold_index',
                'label' => 'KPI-Schwellenwerte',
                'description' => 'KPI-Grenzwerte und Zielwerte konfigurieren',
                'aliases' => ['KPI', 'Kennzahl', 'Schwellenwert', 'Threshold', 'Zielwert', 'Grenzwert', 'Metrik'],
                'icon' => 'fa-icon--nav-bar-chart',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => 'analytics',
            ],
            // ── Reports ───────────────────────────────────────────────────────
            [
                'route' => 'app_reports_index',
                'label' => 'Berichte',
                'description' => 'Management-Reports, Gap-Report, Compliance-Exporte',
                'aliases' => ['Reports', 'Bericht', 'Berichte', 'Auswertung', 'Auswertungen', 'Statistik', 'Export', 'PDF', 'Excel'],
                'icon' => 'fa-icon--nav-reports',
                'roles' => ['ROLE_AUDITOR'],
                'requiresModule' => 'report_builder',
            ],
            [
                'route' => 'report_builder_index',
                'label' => 'Report-Builder',
                'description' => 'Eigene Berichte gestalten, Widgets kombinieren',
                'aliases' => ['Report-Builder', 'Berichts-Designer', 'Custom Report', 'Bericht erstellen', 'Report Designer', 'Berichtsersteller'],
                'icon' => 'fa-icon--nav-reports',
                'roles' => ['ROLE_MANAGER'],
                'requiresModule' => 'report_builder',
            ],
            [
                'route' => 'app_group_report_tree',
                'label' => 'Konzern-/Portfolio-Report',
                'description' => 'Holding-Übersicht, Multi-Tenant Compliance-Status',
                'aliases' => ['Konzern', 'Portfolio', 'Holding', 'Gruppe', 'Group Report', 'Portfolio-Report', 'Konzernbericht', 'Multi-Tenant'],
                'icon' => 'fa-icon--nav-reports',
                'roles' => ['ROLE_MANAGER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'admin_compliance_index',
                'label' => 'Compliance & Gap-Analyse',
                'description' => 'Compliance-Frameworks, Lücken-Analyse, offene Anforderungen',
                'aliases' => ['Gap', 'Lücke', 'Lücken', 'Lücken-Analyse', 'Gap Analysis', 'Gap-Report', 'Compliance Gap', 'offene Anforderungen', 'Compliance Hub'],
                'icon' => 'fa-icon--compliance-shield',
                'roles' => ['ROLE_AUDITOR'],
                'requiresModule' => 'compliance',
            ],
            [
                'route' => 'app_scheduled_report_index',
                'label' => 'Geplante Berichte',
                'description' => 'Berichte automatisch per Zeitplan versenden',
                'aliases' => ['Schedule', 'Scheduled', 'Geplante Berichte', 'Cron', 'Automatisch', 'Zeitplan', 'Recurring', 'Wiederkehrend'],
                'icon' => 'fa-icon--nav-reports',
                'roles' => ['ROLE_MANAGER'],
                'requiresModule' => 'report_builder',
            ],
            // ── Backup / Restore ──────────────────────────────────────────────
            [
                'route' => 'data_backup_index',
                'label' => 'Backup',
                'description' => 'Datensicherung erstellen, herunterladen und verwalten',
                'aliases' => ['Backup', 'Sicherung', 'Datensicherung', 'Sichern', 'Export', 'Snapshot', 'Archiv'],
                'icon' => 'fa-icon--nav-wrench',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'data_backup_index',
                'label' => 'Restore / Wiederherstellung',
                'description' => 'Backup einspielen und Daten wiederherstellen',
                'aliases' => ['Restore', 'Wiederherstellung', 'Recovery', 'Wiederherstellen', 'Einspielen', 'Import'],
                'icon' => 'fa-icon--nav-wrench',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── Modules / Lifecycle / Workflow ────────────────────────────────
            [
                'route' => 'admin_modules_index',
                'label' => 'Module-Aktivierung',
                'description' => 'Compliance-Module ein- und ausschalten',
                'aliases' => ['Module', 'Modul', 'Funktionen', 'Features', 'Aktivieren', 'Deaktivieren', 'Modul-Konfiguration', 'Lizenz'],
                'icon' => 'fa-icon--nav-grid',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'admin_lifecycle_overrides_index',
                'label' => 'Lifecycle Overrides',
                'description' => 'Workflow-Konfiguration pro Tenant (Rollen, 4-Augen, Pflichtbegründung)',
                'aliases' => ['Lifecycle', 'Workflow', 'Status-Übergang', 'Approval', 'Genehmigung', '4-Augen', 'Vier-Augen', 'Status'],
                'icon' => 'fa-icon--nav-workflow',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'admin_workflow_overlay_index',
                'label' => 'Workflow-Konfiguration',
                'description' => 'Regulatorische Workflows verwalten',
                'aliases' => ['Workflow', 'Regulatorisch', 'Prozess', 'Process', 'Genehmigung', 'Approval'],
                'icon' => 'fa-icon--nav-workflow',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── Notifications ─────────────────────────────────────────────────
            [
                'route' => 'admin_notification_channel_index',
                'label' => 'Benachrichtigungs-Kanäle',
                'description' => 'E-Mail-, Slack-, Webhook-Kanäle konfigurieren',
                'aliases' => ['Benachrichtigung', 'Notification', 'Slack', 'Email', 'E-Mail', 'Teams', 'Webhook', 'Channel', 'Kanal'],
                'icon' => 'fa-icon--nav-bell',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => 'notifications',
            ],
            [
                'route' => 'admin_notification_rule_index',
                'label' => 'Benachrichtigungs-Regeln',
                'description' => 'Regelbasierte Trigger für automatische Alerts',
                'aliases' => ['Regel', 'Rule', 'Trigger', 'Bedingung', 'Alert', 'Alarm', 'Automatisch', 'Benachrichtigung'],
                'icon' => 'fa-icon--nav-bell',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => 'notifications',
            ],
            // ── Tags ──────────────────────────────────────────────────────────
            [
                'route' => 'admin_tag_index',
                'label' => 'Tags verwalten',
                'description' => 'Tags und Labels für Entitäten erstellen und pflegen',
                'aliases' => ['Tags', 'Labels', 'Schlagwort', 'Schlagwörter', 'Markierung', 'Label', 'Tag'],
                'icon' => 'fa-icon--nav-grid',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── Dashboards / Personas ─────────────────────────────────────────
            [
                'route' => 'app_dashboard',
                'label' => 'Dashboard',
                'description' => 'Persönliches ISMS-Dashboard mit KPIs',
                'aliases' => ['Dashboard', 'Startseite', 'Übersicht', 'Home', 'Cockpit'],
                'icon' => 'fa-icon--nav-home',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_dashboard_ciso',
                'label' => 'CISO-Dashboard',
                'description' => 'Sicherheitslage, Top-Risiken, Compliance-Status',
                'aliases' => ['CISO', 'Dashboard', 'Sicherheitslage', 'Security', 'Cockpit'],
                'icon' => 'fa-icon--nav-home',
                'roles' => ['ROLE_CISO'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_dashboard_dpo',
                'label' => 'DPO-Dashboard',
                'description' => 'Datenschutz-Übersicht, VVT, DSR-Status',
                'aliases' => ['DPO', 'Datenschutz', 'Privacy', 'DSGVO', 'GDPR', 'Dashboard', 'Cockpit'],
                'icon' => 'fa-icon--nav-home',
                'roles' => ['ROLE_DPO'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_dashboard_compliance_manager',
                'label' => 'Compliance-Manager-Dashboard',
                'description' => 'Framework-Status, offene Maßnahmen',
                'aliases' => ['Compliance Manager', 'CM', 'GRC', 'Framework', 'Dashboard', 'Cockpit', 'RM'],
                'icon' => 'fa-icon--nav-home',
                'roles' => ['ROLE_COMPLIANCE_MANAGER'],
                'requiresModule' => null,
            ],
            // ── Onboarding / Setup ────────────────────────────────────────────
            [
                'route' => 'setup_wizard_index',
                'label' => 'Setup-Wizard',
                'description' => 'Onboarding-Tour, Ersteinrichtung',
                'aliases' => ['Setup', 'Onboarding', 'Wizard', 'Erste Schritte', 'Getting Started', 'Einrichten', 'Einrichtung'],
                'icon' => 'fa-icon--nav-stars',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── Glossary ──────────────────────────────────────────────────────
            [
                'route' => 'app_help_glossary',
                'label' => 'Glossar',
                'description' => 'ISMS-Akronyme und Fachbegriffe',
                'aliases' => ['Glossary', 'Begriff', 'Begriffe', 'Definition', 'Akronym', 'Akronyme', 'Lexikon'],
                'icon' => 'fa-icon--nav-book',
                'roles' => ['ROLE_USER'],
                'requiresModule' => null,
            ],
            // ── Maintenance / DevOps ──────────────────────────────────────────
            [
                'route' => 'app_quick_fix_index',
                'label' => 'Quick-Fix',
                'description' => 'Migrations, Schema-Drift, Datenreparatur per Web-UI',
                'aliases' => ['Quick-Fix', 'Reparatur', 'Repair', 'Schema', 'Drift', 'Migration', 'Migrations'],
                'icon' => 'fa-icon--nav-wrench',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'admin_data_repair_index',
                'label' => 'Daten-Reparatur',
                'description' => 'Orphans zuweisen, Tenant-Mismatches fixen, Duplikate bereinigen',
                'aliases' => ['Daten-Reparatur', 'Data-Repair', 'Orphan', 'Verwaiste Daten', 'Duplikate', 'Bereinigung', 'Repair'],
                'icon' => 'fa-icon--nav-wrench',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'admin_queue_status',
                'label' => 'Queue-Status',
                'description' => 'Worker-Health und ausstehende Messenger-Jobs',
                'aliases' => ['Queue', 'Worker', 'Messenger', 'Async-Job', 'Hintergrund', 'Background', 'Job', 'Jobs'],
                'icon' => 'fa-icon--nav-clock',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── Industry Baseline ─────────────────────────────────────────────
            [
                'route' => 'app_industry_baseline_index',
                'label' => 'Branchen-Baseline',
                'description' => 'Branchenspezifische Sicherheitsanforderungen und BSI-Bausteine',
                'aliases' => ['Industry', 'Branche', 'Sektor', 'Baseline', 'BSI-Bausteine', 'Branchen-Baseline', 'Industriestandard', 'Sector'],
                'icon' => 'fa-icon--nav-grid',
                'roles' => ['ROLE_MANAGER'],
                'requiresModule' => null,
            ],
            // ── Locations ─────────────────────────────────────────────────────
            [
                'route' => 'app_location_index',
                'label' => 'Standorte',
                'description' => 'Standorte und Niederlassungen verwalten',
                'aliases' => ['Standort', 'Standorte', 'Location', 'Site', 'Niederlassung', 'Büro', 'Office', 'Filiale'],
                'icon' => 'fa-icon--nav-location',
                'roles' => ['ROLE_MANAGER'],
                'requiresModule' => null,
            ],
            // ── API Documentation ─────────────────────────────────────────────
            [
                'route' => 'api_doc',
                'label' => 'API-Dokumentation',
                'description' => 'REST-API, OpenAPI / Swagger Spezifikation',
                'aliases' => ['API', 'REST', 'JSON', 'Doku', 'Documentation', 'OpenAPI', 'Swagger', 'Schnittstelle', 'Integration'],
                'icon' => 'fa-icon--nav-book',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
                'params' => ['_format' => 'html'],
            ],
            // ── Compliance Hub ────────────────────────────────────────────────
            [
                'route' => 'admin_tenant_compliance_settings_current',
                'label' => 'Compliance-Einstellungen',
                'description' => 'Compliance-Frameworks aktivieren: ISO 27001, TISAX, DORA, NIS2, BSI',
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
