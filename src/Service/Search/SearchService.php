<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\Asset;
use App\Entity\AuditFinding;
use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\ChangeRequest;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Consent;
use App\Entity\Control;
use App\Entity\CorrectiveAction;
use App\Entity\CrisisTeam;
use App\Entity\DataBreach;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\DataSubjectRequest;
use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\InterestedParty;
use App\Entity\InternalAudit;
use App\Entity\ISMSObjective;
use App\Entity\ManagementReview;
use App\Entity\Patch;
use App\Entity\Person;
use App\Entity\ProcessingActivity;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Entity\ThreatIntelligence;
use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\Vulnerability;
use App\Repository\AssetRepository;
use App\Repository\AuditFindingRepository;
use App\Repository\BCExerciseRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ChangeRequestRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ConsentRepository;
use App\Repository\ControlRepository;
use App\Repository\CorrectiveActionRepository;
use App\Repository\CrisisTeamRepository;
use App\Repository\DataBreachRepository;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\InterestedPartyRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\ISMSObjectiveRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\PatchRepository;
use App\Repository\PersonRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\RiskRepository;
use App\Repository\SupplierRepository;
use App\Repository\ThreatIntelligenceRepository;
use App\Repository\TrainingRepository;
use App\Repository\VulnerabilityRepository;
use App\Service\ModuleConfigurationService;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class SearchService
{
    private const MAX_RESULTS_PER_CATEGORY = 5;

    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly TrainingRepository $trainingRepository,
        private readonly ControlRepository $controlRepository,
        private readonly DocumentRepository $documentRepository,
        private readonly SupplierRepository $supplierRepository,
        private readonly ProcessingActivityRepository $processingActivityRepository,
        private readonly DataProtectionImpactAssessmentRepository $dpiaRepository,
        private readonly DataBreachRepository $dataBreachRepository,
        private readonly AuditFindingRepository $auditFindingRepository,
        private readonly CorrectiveActionRepository $correctiveActionRepository,
        private readonly ChangeRequestRepository $changeRequestRepository,
        private readonly InternalAuditRepository $internalAuditRepository,
        private readonly BusinessProcessRepository $businessProcessRepository,
        private readonly BusinessContinuityPlanRepository $bcPlanRepository,
        private readonly BCExerciseRepository $bcExerciseRepository,
        private readonly CrisisTeamRepository $crisisTeamRepository,
        private readonly ManagementReviewRepository $managementReviewRepository,
        private readonly ISMSObjectiveRepository $ismsObjectiveRepository,
        private readonly VulnerabilityRepository $vulnerabilityRepository,
        private readonly PatchRepository $patchRepository,
        private readonly ThreatIntelligenceRepository $threatIntelligenceRepository,
        private readonly PersonRepository $personRepository,
        private readonly InterestedPartyRepository $interestedPartyRepository,
        private readonly ConsentRepository $consentRepository,
        private readonly DataSubjectRequestRepository $dataSubjectRequestRepository,
        private readonly ComplianceFrameworkRepository $complianceFrameworkRepository,
        private readonly ComplianceRequirementRepository $complianceRequirementRepository,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly AuthorizationCheckerInterface $authChecker,
        private readonly ModuleConfigurationService $moduleConfig,
    ) {}

    /**
     * Run a global search across all entity categories and navigation targets.
     *
     * @return array{total: int, navigation: array, assets: array, risks: array, controls: array, incidents: array, trainings: array, documents: array, suppliers: array, processing_activities: array, dpias: array, data_breaches: array, audit_findings: array, corrective_actions: array, change_requests: array, internal_audits: array, business_processes: array, bc_plans: array, bc_exercises: array, crisis_teams: array, management_reviews: array, objectives: array, vulnerabilities: array, patches: array, threat_intelligence: array, persons: array, interested_parties: array, consents: array, data_subject_requests: array, compliance_frameworks: array, compliance_requirements: array, query: string}
     */
    public function search(string $query, ?Tenant $tenant): array
    {
        $navigation = $this->searchNavigation($query);
        $assets = $this->searchAssets($query, $tenant);
        $risks = $this->searchRisks($query, $tenant);
        $controls = $this->searchControls($query, $tenant);
        $incidents = $this->searchIncidents($query, $tenant);
        $trainings = $this->searchTrainings($query, $tenant);
        $documents = $this->searchDocuments($query, $tenant);
        $suppliers = $this->searchSuppliers($query, $tenant);
        $processingActivities = $this->searchProcessingActivities($query, $tenant);
        $dpias = $this->searchDpias($query, $tenant);
        $dataBreaches = $this->searchDataBreaches($query, $tenant);
        $auditFindings = $this->searchAuditFindings($query, $tenant);
        $correctiveActions = $this->searchCorrectiveActions($query, $tenant);
        $changeRequests = $this->searchChangeRequests($query, $tenant);
        $internalAudits = $this->searchInternalAudits($query, $tenant);
        $businessProcesses = $this->searchBusinessProcesses($query, $tenant);
        $bcPlans = $this->searchBcPlans($query, $tenant);
        $bcExercises = $this->searchBcExercises($query, $tenant);
        $crisisTeams = $this->searchCrisisTeams($query, $tenant);
        $managementReviews = $this->searchManagementReviews($query, $tenant);
        $objectives = $this->searchObjectives($query, $tenant);
        $vulnerabilities = $this->searchVulnerabilities($query, $tenant);
        $patches = $this->searchPatches($query, $tenant);
        $threatIntelligence = $this->searchThreatIntelligence($query, $tenant);
        $persons = $this->searchPersons($query, $tenant);
        $interestedParties = $this->searchInterestedParties($query, $tenant);
        $consents = $this->searchConsents($query, $tenant);
        $dataSubjectRequests = $this->searchDataSubjectRequests($query, $tenant);
        $complianceFrameworks = $this->searchComplianceFrameworks($query);
        $complianceRequirements = $this->searchComplianceRequirements($query);

        $total = count($navigation)
            + count($assets) + count($risks) + count($controls)
            + count($incidents) + count($trainings) + count($documents)
            + count($suppliers) + count($processingActivities) + count($dpias)
            + count($dataBreaches) + count($auditFindings) + count($correctiveActions)
            + count($changeRequests) + count($internalAudits) + count($businessProcesses)
            + count($bcPlans) + count($bcExercises) + count($crisisTeams)
            + count($managementReviews) + count($objectives) + count($vulnerabilities)
            + count($patches) + count($threatIntelligence) + count($persons)
            + count($interestedParties) + count($consents) + count($dataSubjectRequests)
            + count($complianceFrameworks) + count($complianceRequirements);

        return [
            'total' => $total,
            'navigation' => $navigation,
            'assets' => $assets,
            'risks' => $risks,
            'controls' => $controls,
            'incidents' => $incidents,
            'trainings' => $trainings,
            'documents' => $documents,
            'suppliers' => $suppliers,
            'processing_activities' => $processingActivities,
            'dpias' => $dpias,
            'data_breaches' => $dataBreaches,
            'audit_findings' => $auditFindings,
            'corrective_actions' => $correctiveActions,
            'change_requests' => $changeRequests,
            'internal_audits' => $internalAudits,
            'business_processes' => $businessProcesses,
            'bc_plans' => $bcPlans,
            'bc_exercises' => $bcExercises,
            'crisis_teams' => $crisisTeams,
            'management_reviews' => $managementReviews,
            'objectives' => $objectives,
            'vulnerabilities' => $vulnerabilities,
            'patches' => $patches,
            'threat_intelligence' => $threatIntelligence,
            'persons' => $persons,
            'interested_parties' => $interestedParties,
            'consents' => $consents,
            'data_subject_requests' => $dataSubjectRequests,
            'compliance_frameworks' => $complianceFrameworks,
            'compliance_requirements' => $complianceRequirements,
            'query' => $query,
        ];
    }

    /**
     * Search navigation targets (settings, admin pages, dashboards).
     * Returns matches first in result order. Role-filtered per current user.
     * Each entry may carry an optional `aliases` array for synonym/keyword matching.
     */
    public function searchNavigation(string $query): array
    {
        $navMap = [
            // ── Core / Admin ──────────────────────────────────────────────────
            [
                'route' => 'admin_dashboard',
                'label' => 'Admin Dashboard',
                'description' => 'Tenant- und Benutzerverwaltung, System-Status',
                'aliases' => ['Admin', 'Verwaltung', 'System'],
                'icon' => 'fa-icon--nav-settings',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'admin_hub_index',
                'label' => 'Admin-Hub',
                'description' => 'Zentraler Einstieg für alle Admin-Funktionen',
                'aliases' => ['Admin', 'Verwaltung', 'Settings', 'Einstellungen'],
                'icon' => 'fa-icon--nav-settings',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── Organisation / Tenant ─────────────────────────────────────────
            [
                'route' => 'tenant_management_index',
                'label' => 'Organisations-Einstellungen',
                'description' => 'Organisationen verwalten: Name, Kontakt, ISMS-Scope',
                'aliases' => ['Organisation', 'Organization', 'Tenant', 'Mandant', 'Firma', 'Company', 'Einstellungen', 'Settings', 'Konfiguration', 'Config'],
                'icon' => 'fa-icon--nav-settings',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_admin_tenant_email_branding',
                'label' => 'Branding & Logo',
                'description' => 'Logo, Farben, CI, E-Mail-Design anpassen',
                'aliases' => ['Logo', 'Branding', 'Theme', 'Farben', 'Design', 'CI', 'Corporate Identity', 'E-Mail-Design', 'Email', 'Erscheinungsbild'],
                'icon' => 'fa-icon--nav-settings',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── User & Role management ─────────────────────────────────────────
            [
                'route' => 'user_management_index',
                'label' => 'Benutzerverwaltung',
                'description' => 'Benutzer anlegen, Rollen vergeben, Passwörter zurücksetzen',
                'aliases' => ['Benutzer', 'User', 'Konto', 'Account', 'Mitarbeiter', 'Nutzer', 'Passwort', 'Password'],
                'icon' => 'fa-icon--nav-user',
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
                'icon' => 'fa-icon--nav-audit',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            [
                'route' => 'app_audit_freeze_index',
                'label' => 'Audit-Freeze',
                'description' => 'ISMS-Status einfrieren für externe Audits (Read-Only-Modus)',
                'aliases' => ['Audit-Freeze', 'Freeze', 'Einfrieren', 'Read-Only', 'Auditierung sperren', 'Zertifizierung', 'Snapshot'],
                'icon' => 'fa-icon--nav-audit',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => null,
            ],
            // ── SoA / Controls ────────────────────────────────────────────────
            [
                'route' => 'app_soa_index',
                'label' => 'SoA – Statement of Applicability',
                'description' => 'ISO 27001 Annex-A Controls, Anwendbarkeitserklärung',
                'aliases' => ['SoA', 'Anwendbarkeitserklärung', 'Annex A', 'Kontrollen', 'controls statement', 'Statement of Applicability'],
                'icon' => 'fa-icon--nav-control',
                'roles' => ['ROLE_USER'],
                'requiresModule' => 'controls',
            ],
            // ── Risk ──────────────────────────────────────────────────────────
            [
                'route' => 'app_risk_appetite_index',
                'label' => 'Risikoappetit',
                'description' => 'Risikotoleranz und Schwellenwerte konfigurieren',
                'aliases' => ['Risikoappetit', 'Risikotoleranz', 'Risk Appetite', 'Schwellenwert', 'Threshold'],
                'icon' => 'fa-icon--nav-risk',
                'roles' => ['ROLE_MANAGER'],
                'requiresModule' => 'risk_appetite',
            ],
            // ── Analytics / KPI ───────────────────────────────────────────────
            [
                'route' => 'app_analytics_dashboard',
                'label' => 'Analytics',
                'description' => 'KPI-Übersicht, Diagramme, Trend-Auswertungen',
                'aliases' => ['KPI', 'Kennzahl', 'Kennzahlen', 'Metrik', 'Metric', 'Diagramm', 'Chart', 'Trend', 'Statistik', 'Statistics'],
                'icon' => 'fa-icon--nav-chart',
                'roles' => ['ROLE_MANAGER'],
                'requiresModule' => 'analytics',
            ],
            [
                'route' => 'admin_kpi_threshold_index',
                'label' => 'KPI-Schwellenwerte',
                'description' => 'KPI-Grenzwerte und Zielwerte konfigurieren',
                'aliases' => ['KPI', 'Kennzahl', 'Schwellenwert', 'Threshold', 'Zielwert', 'Grenzwert', 'Metrik'],
                'icon' => 'fa-icon--nav-chart',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => 'analytics',
            ],
            // ── Reports ───────────────────────────────────────────────────────
            [
                'route' => 'app_reports_index',
                'label' => 'Berichte',
                'description' => 'Management-Reports, Gap-Report, Compliance-Exporte',
                'aliases' => ['Reports', 'Bericht', 'Berichte', 'Auswertung', 'Auswertungen', 'Statistik', 'Export', 'PDF', 'Excel'],
                'icon' => 'fa-icon--nav-report',
                'roles' => ['ROLE_AUDITOR'],
                'requiresModule' => 'report_builder',
            ],
            [
                'route' => 'report_builder_index',
                'label' => 'Report-Builder',
                'description' => 'Eigene Berichte gestalten, Widgets kombinieren',
                'aliases' => ['Report-Builder', 'Berichts-Designer', 'Custom Report', 'Bericht erstellen', 'Report Designer', 'Berichtsersteller'],
                'icon' => 'fa-icon--nav-report',
                'roles' => ['ROLE_MANAGER'],
                'requiresModule' => 'report_builder',
            ],
            [
                'route' => 'app_group_report_tree',
                'label' => 'Konzern-/Portfolio-Report',
                'description' => 'Holding-Übersicht, Multi-Tenant Compliance-Status',
                'aliases' => ['Konzern', 'Portfolio', 'Holding', 'Gruppe', 'Group Report', 'Portfolio-Report', 'Konzernbericht', 'Multi-Tenant'],
                'icon' => 'fa-icon--nav-report',
                'roles' => ['ROLE_MANAGER'],
                'requiresModule' => null,
            ],
            [
                'route' => 'admin_compliance_index',
                'label' => 'Compliance & Gap-Analyse',
                'description' => 'Compliance-Frameworks, Lücken-Analyse, offene Anforderungen',
                'aliases' => ['Gap', 'Lücke', 'Lücken', 'Lücken-Analyse', 'Gap Analysis', 'Gap-Report', 'Compliance Gap', 'offene Anforderungen', 'Compliance Hub'],
                'icon' => 'fa-icon--nav-compliance',
                'roles' => ['ROLE_AUDITOR'],
                'requiresModule' => 'compliance',
            ],
            [
                'route' => 'app_scheduled_report_index',
                'label' => 'Geplante Berichte',
                'description' => 'Berichte automatisch per Zeitplan versenden',
                'aliases' => ['Schedule', 'Scheduled', 'Geplante Berichte', 'Cron', 'Automatisch', 'Zeitplan', 'Recurring', 'Wiederkehrend'],
                'icon' => 'fa-icon--nav-report',
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
                'icon' => 'fa-icon--nav-notification',
                'roles' => ['ROLE_ADMIN'],
                'requiresModule' => 'notifications',
            ],
            [
                'route' => 'admin_notification_rule_index',
                'label' => 'Benachrichtigungs-Regeln',
                'description' => 'Regelbasierte Trigger für automatische Alerts',
                'aliases' => ['Regel', 'Rule', 'Trigger', 'Bedingung', 'Alert', 'Alarm', 'Automatisch', 'Benachrichtigung'],
                'icon' => 'fa-icon--nav-notification',
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
                'icon' => 'fa-icon--nav-star',
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
                'icon' => 'fa-icon--nav-asset',
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
                'icon' => 'fa-icon--nav-compliance',
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
                } catch (\Symfony\Component\Routing\Exception\MissingMandatoryParametersException) {
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

    public function searchAssets(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('assets')) {
            return [];
        }

        $qb = $this->assetRepository->createQueryBuilder('a')
            ->where('a.name LIKE :query OR a.description LIKE :query OR a.owner LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('a.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Asset $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_asset_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-asset',
            'type' => 'asset',
            'badge' => $e->getAssetType(),
        ], $qb->getQuery()->getResult());
    }

    public function searchRisks(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('risks')) {
            return [];
        }

        $qb = $this->riskRepository->createQueryBuilder('r')
            ->where('r.title LIKE :query OR r.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('r.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(function (Risk $e): array {
            $level = $e->getInherentRiskLevel();
            $badge = $level >= 15 ? 'Hoch' : ($level >= 9 ? 'Mittel' : 'Niedrig');

            return [
                'id' => $e->getId(),
                'title' => $e->getTitle(),
                'description' => $this->truncate($e->getDescription(), 100),
                'url' => $this->urlGenerator->generate('app_risk_show', ['id' => $e->getId()]),
                'icon' => 'fa-icon--nav-risk',
                'type' => 'risk',
                'badge' => $badge,
            ];
        }, $qb->getQuery()->getResult());
    }

    public function searchControls(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('controls')) {
            return [];
        }

        $qb = $this->controlRepository->createQueryBuilder('c')
            ->where('c.controlId LIKE :query OR c.name LIKE :query OR c.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('c.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Control $e): array => [
            'id' => $e->getId(),
            'title' => $e->getControlId() . ' - ' . $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_soa_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-control',
            'type' => 'control',
            'badge' => $e->getImplementationStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchIncidents(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('incidents')) {
            return [];
        }

        $qb = $this->incidentRepository->createQueryBuilder('i')
            ->where('i.title LIKE :query OR i.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('i.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Incident $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_incident_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-incident',
            'type' => 'incident',
            'badge' => $e->getSeverity()?->value,
        ], $qb->getQuery()->getResult());
    }

    public function searchTrainings(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('training')) {
            return [];
        }

        $qb = $this->trainingRepository->createQueryBuilder('t')
            ->where('t.title LIKE :query OR t.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('t.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Training $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_training_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-training',
            'type' => 'training',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchDocuments(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('documents')) {
            return [];
        }

        $qb = $this->documentRepository->createQueryBuilder('d')
            ->where('d.originalFilename LIKE :query OR d.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('d.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Document $e): array => [
            'id' => $e->getId(),
            'title' => $e->getOriginalFilename() ?? $e->getFilename(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_document_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-document',
            'type' => 'document',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchSuppliers(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('suppliers')) {
            return [];
        }

        $qb = $this->supplierRepository->createQueryBuilder('s')
            ->where('s.name LIKE :query OR s.description LIKE :query OR s.serviceProvided LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('s.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Supplier $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_supplier_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-supplier',
            'type' => 'supplier',
            'badge' => $e->getCriticality(),
        ], $qb->getQuery()->getResult());
    }

    public function searchProcessingActivities(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('privacy')) {
            return [];
        }

        $qb = $this->processingActivityRepository->createQueryBuilder('pa')
            ->where('pa.name LIKE :query OR pa.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('pa.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(ProcessingActivity $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_processing_activity_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-privacy',
            'type' => 'processing_activity',
            'badge' => null,
        ], $qb->getQuery()->getResult());
    }

    public function searchDpias(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('privacy')) {
            return [];
        }

        $qb = $this->dpiaRepository->createQueryBuilder('dp')
            ->where('dp.title LIKE :query OR dp.processingDescription LIKE :query OR dp.processingPurposes LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('dp.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(DataProtectionImpactAssessment $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getProcessingDescription(), 100),
            'url' => $this->urlGenerator->generate('app_dpia_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-privacy',
            'type' => 'dpia',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchDataBreaches(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('privacy')) {
            return [];
        }

        $qb = $this->dataBreachRepository->createQueryBuilder('db')
            ->where('db.title LIKE :query OR db.breachNature LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('db.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(DataBreach $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle() ?? $e->getReferenceNumber(),
            'description' => $this->truncate($e->getBreachNature(), 100),
            'url' => $this->urlGenerator->generate('app_data_breach_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-breach',
            'type' => 'data_breach',
            'badge' => $e->getSeverity(),
        ], $qb->getQuery()->getResult());
    }

    public function searchAuditFindings(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('audits')) {
            return [];
        }

        $qb = $this->auditFindingRepository->createQueryBuilder('af')
            ->where('af.title LIKE :query OR af.description LIKE :query OR af.clauseReference LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('af.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(AuditFinding $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_audit_finding_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-audit',
            'type' => 'audit_finding',
            'badge' => $e->getSeverity(),
        ], $qb->getQuery()->getResult());
    }

    public function searchCorrectiveActions(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('corrective_actions')) {
            return [];
        }

        $qb = $this->correctiveActionRepository->createQueryBuilder('ca')
            ->where('ca.title LIKE :query OR ca.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('ca.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(CorrectiveAction $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_corrective_action_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-corrective',
            'type' => 'corrective_action',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchChangeRequests(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('change_requests')) {
            return [];
        }

        $qb = $this->changeRequestRepository->createQueryBuilder('cr')
            ->where('cr.title LIKE :query OR cr.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('cr.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(ChangeRequest $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_change_request_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-change',
            'type' => 'change_request',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchInternalAudits(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('audits')) {
            return [];
        }

        $qb = $this->internalAuditRepository->createQueryBuilder('ia')
            ->where('ia.title LIKE :query OR ia.scope LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('ia.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(InternalAudit $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getScope(), 100),
            'url' => $this->urlGenerator->generate('app_audit_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-audit',
            'type' => 'internal_audit',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchBusinessProcesses(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('bcm')) {
            return [];
        }

        $qb = $this->businessProcessRepository->createQueryBuilder('bp')
            ->where('bp.name LIKE :query OR bp.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('bp.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(BusinessProcess $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_business_process_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-process',
            'type' => 'business_process',
            'badge' => null,
        ], $qb->getQuery()->getResult());
    }

    public function searchBcPlans(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('bcm')) {
            return [];
        }

        $qb = $this->bcPlanRepository->createQueryBuilder('bc')
            ->where('bc.name LIKE :query OR bc.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('bc.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(BusinessContinuityPlan $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_bc_plan_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-bcm',
            'type' => 'bc_plan',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchBcExercises(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('bcm')) {
            return [];
        }

        $qb = $this->bcExerciseRepository->createQueryBuilder('bce')
            ->where('bce.name LIKE :query OR bce.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('bce.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(BCExercise $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_bc_exercise_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-bcm',
            'type' => 'bc_exercise',
            'badge' => $e->getExerciseType(),
        ], $qb->getQuery()->getResult());
    }

    public function searchCrisisTeams(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('bcm')) {
            return [];
        }

        $qb = $this->crisisTeamRepository->createQueryBuilder('ct')
            ->where('ct.teamName LIKE :query OR ct.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('ct.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(CrisisTeam $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTeamName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_crisis_team_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-bcm',
            'type' => 'crisis_team',
            'badge' => $e->getTeamType(),
        ], $qb->getQuery()->getResult());
    }

    public function searchManagementReviews(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('reviews')) {
            return [];
        }

        $qb = $this->managementReviewRepository->createQueryBuilder('mr')
            ->where('mr.title LIKE :query OR mr.decisions LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('mr.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(ManagementReview $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDecisions(), 100),
            'url' => $this->urlGenerator->generate('app_management_review_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-review',
            'type' => 'management_review',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchObjectives(string $query, ?Tenant $tenant): array
    {
        $qb = $this->ismsObjectiveRepository->createQueryBuilder('o')
            ->where('o.title LIKE :query OR o.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('o.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(ISMSObjective $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_objective_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-objective',
            'type' => 'objective',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchVulnerabilities(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('vulnerability_intel')) {
            return [];
        }

        $qb = $this->vulnerabilityRepository->createQueryBuilder('v')
            ->where('v.cveId LIKE :query OR v.title LIKE :query OR v.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('v.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Vulnerability $e): array => [
            'id' => $e->getId(),
            'title' => ($e->getCveId() ? $e->getCveId() . ' – ' : '') . $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_vulnerability_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-vulnerability',
            'type' => 'vulnerability',
            'badge' => $e->getSeverity(),
        ], $qb->getQuery()->getResult());
    }

    public function searchPatches(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('patches')) {
            return [];
        }

        $qb = $this->patchRepository->createQueryBuilder('p')
            ->where('p.title LIKE :query OR p.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('p.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Patch $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_patch_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-patch',
            'type' => 'patch',
            'badge' => $e->getPriority(),
        ], $qb->getQuery()->getResult());
    }

    public function searchThreatIntelligence(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('vulnerability_intel')) {
            return [];
        }

        $qb = $this->threatIntelligenceRepository->createQueryBuilder('ti')
            ->where('ti.title LIKE :query OR ti.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('ti.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(ThreatIntelligence $e): array => [
            'id' => $e->getId(),
            'title' => $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_threat_intelligence_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-threat',
            'type' => 'threat_intelligence',
            'badge' => $e->getSeverity(),
        ], $qb->getQuery()->getResult());
    }

    public function searchPersons(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('persons')) {
            return [];
        }

        $qb = $this->personRepository->createQueryBuilder('pe')
            ->where('pe.fullName LIKE :query OR pe.email LIKE :query OR pe.jobTitle LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('pe.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Person $e): array => [
            'id' => $e->getId(),
            'title' => $e->getFullName(),
            'description' => $this->truncate(($e->getJobTitle() ?? '') . ($e->getEmail() ? ' · ' . $e->getEmail() : ''), 100),
            'url' => $this->urlGenerator->generate('app_person_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-user',
            'type' => 'person',
            'badge' => $e->getPersonType(),
        ], $qb->getQuery()->getResult());
    }

    public function searchInterestedParties(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('interested_parties')) {
            return [];
        }

        $qb = $this->interestedPartyRepository->createQueryBuilder('ip')
            ->where('ip.name LIKE :query OR ip.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('ip.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(InterestedParty $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_interested_party_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-stakeholder',
            'type' => 'interested_party',
            'badge' => $e->getPartyType(),
        ], $qb->getQuery()->getResult());
    }

    public function searchConsents(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('privacy')) {
            return [];
        }

        $qb = $this->consentRepository->createQueryBuilder('co')
            ->where('co.dataSubjectIdentifier LIKE :query OR co.consentText LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('co.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(Consent $e): array => [
            'id' => $e->getId(),
            'title' => $e->getDataSubjectIdentifier(),
            'description' => $this->truncate($e->getConsentText(), 100),
            'url' => $this->urlGenerator->generate('app_consent_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-privacy',
            'type' => 'consent',
            'badge' => $e->getStatus(),
        ], $qb->getQuery()->getResult());
    }

    public function searchDataSubjectRequests(string $query, ?Tenant $tenant): array
    {
        if (!$this->moduleConfig->isModuleActive('privacy')) {
            return [];
        }

        $qb = $this->dataSubjectRequestRepository->createQueryBuilder('dsr')
            ->where('dsr.dataSubjectName LIKE :query OR dsr.description LIKE :query OR dsr.dataSubjectIdentifier LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        if ($tenant !== null) {
            $qb->andWhere('dsr.tenant = :tenant')->setParameter('tenant', $tenant);
        }

        return array_map(fn(DataSubjectRequest $e): array => [
            'id' => $e->getId(),
            'title' => $e->getDataSubjectName() ?? $e->getDataSubjectIdentifier(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_data_subject_request_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-privacy',
            'type' => 'data_subject_request',
            'badge' => $e->getRequestType(),
        ], $qb->getQuery()->getResult());
    }

    /**
     * Compliance frameworks are NOT tenant-scoped (shared library).
     */
    public function searchComplianceFrameworks(string $query): array
    {
        if (!$this->moduleConfig->isModuleActive('compliance')) {
            return [];
        }

        $qb = $this->complianceFrameworkRepository->createQueryBuilder('cf')
            ->where('cf.name LIKE :query OR cf.version LIKE :query OR cf.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        return array_map(fn(ComplianceFramework $e): array => [
            'id' => $e->getId(),
            'title' => $e->getName() . ($e->getVersion() ? ' ' . $e->getVersion() : ''),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_compliance_framework_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-compliance',
            'type' => 'compliance_framework',
            'badge' => null,
        ], $qb->getQuery()->getResult());
    }

    /**
     * Compliance requirements are NOT tenant-scoped (shared library).
     */
    public function searchComplianceRequirements(string $query): array
    {
        if (!$this->moduleConfig->isModuleActive('compliance')) {
            return [];
        }

        $qb = $this->complianceRequirementRepository->createQueryBuilder('req')
            ->where('req.requirementId LIKE :query OR req.title LIKE :query OR req.description LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->setMaxResults(self::MAX_RESULTS_PER_CATEGORY);

        return array_map(fn(ComplianceRequirement $e): array => [
            'id' => $e->getId(),
            'title' => ($e->getRequirementId() ? $e->getRequirementId() . ' – ' : '') . $e->getTitle(),
            'description' => $this->truncate($e->getDescription(), 100),
            'url' => $this->urlGenerator->generate('app_compliance_requirement_show', ['id' => $e->getId()]),
            'icon' => 'fa-icon--nav-compliance',
            'type' => 'compliance_requirement',
            'badge' => $e->getCategory(),
        ], $qb->getQuery()->getResult());
    }

    private function truncate(?string $text, int $length): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length) . '…';
    }
}
