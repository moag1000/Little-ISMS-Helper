<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\GuidedTourStepOverrideRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Optional-Dependency-Sicht: ModuleConfigurationService wird injiziert,
 * wenn verfügbar — andernfalls werden Modul-Zusatz-Stopps übersprungen
 * (Tests + frische Installationen brauchen den Service nicht).
 *
 * Analog optional: GuidedTourStepOverrideRepository für P5 Tenant-Override.
 */

/**
 * Sprint 13 / S13-2: GuidedTour-Service.
 *
 * Liefert rollenbasierte Tour-Definitionen (Step-Listen) an den
 * Frontend-Stimulus-Controller als JSON. Steps referenzieren
 * Translation-Keys (`guided_tour.<role>.step.<n>.title/body`),
 * Target-Selektoren (DOM-Anker) und optionale URLs für
 * Mehr-Seiten-Touren.
 *
 * Role-Auto-Detect:
 *   - ROLE_AUDITOR                   → auditor-Tour
 *   - ROLE_GROUP_CISO / ROLE_ADMIN   → ciso-Tour
 *   - ROLE_COMPLIANCE_MANAGER        → cm-Tour
 *   - ROLE_ISB / ROLE_MANAGER        → isb-Tour
 *   - ROLE_RISK_OWNER                → risk-owner-Tour
 *   - Fallback                       → junior-Tour
 *
 * Der User kann im Banner die Auto-Zuordnung überschreiben.
 *
 * Modul-Awareness (Phase C): BSI/GDPR/BCM-spezifische Zusatz-Stopps
 * werden dynamisch an Junior-/ISB-Touren angehängt.
 */
final class GuidedTourService
{
    public const TOUR_JUNIOR = 'junior';
    public const TOUR_CM = 'cm';
    public const TOUR_CISO = 'ciso';
    public const TOUR_ISB = 'isb';
    public const TOUR_RISK_OWNER = 'risk_owner';
    public const TOUR_AUDITOR = 'auditor';

    /** @var list<string> */
    public const ALL_TOURS = [
        self::TOUR_JUNIOR,
        self::TOUR_CM,
        self::TOUR_CISO,
        self::TOUR_ISB,
        self::TOUR_RISK_OWNER,
        self::TOUR_AUDITOR,
    ];

    public function __construct(
        private readonly AuthorizationCheckerInterface $authChecker,
        private readonly ?ModuleConfigurationService $moduleConfig = null,
        private readonly ?GuidedTourStepOverrideRepository $overrideRepository = null,
        private readonly ?TenantContext $tenantContext = null,
        private readonly ?UrlGeneratorInterface $urlGenerator = null,
        private readonly ?RequestStack $requestStack = null,
    ) {
    }

    /**
     * Generiere Locale-aware URL aus Route-Name. Fallback null wenn
     * UrlGenerator nicht verfügbar (Tests ohne volle Router-Wiring).
     */
    private function urlFor(?string $routeName, array $params = []): ?string
    {
        if ($routeName === null || $this->urlGenerator === null) {
            return null;
        }
        $locale = $this->requestStack?->getCurrentRequest()?->getLocale() ?? 'de';
        try {
            return $this->urlGenerator->generate($routeName, array_merge(['_locale' => $locale], $params));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Prüft Override-Repository für einen gegebenen Step. Gibt Override-
     * Texte zurück oder null wenn Default gelten sollen.
     *
     * @return array{title: string, body: string}|null
     */
    public function resolveOverride(string $tourId, string $stepId, string $locale): ?array
    {
        if ($this->overrideRepository === null) {
            return null;
        }
        $tenant = $this->tenantContext?->getCurrentTenant();
        $override = $this->overrideRepository->findEffective($tenant, $tourId, $stepId, $locale);
        if ($override === null) {
            return null;
        }
        return [
            'title' => $override->getTitleOverride() ?? '',
            'body' => $override->getBodyOverride() ?? '',
        ];
    }

    /**
     * Automatisch die wahrscheinlich passende Tour anhand der granted
     * Roles wählen. User kann im Banner überschreiben.
     */
    public function autoDetectTour(User $user): string
    {
        // Priorität: spezifischere Rollen zuerst (Auditor schlägt Admin),
        // Fallback Junior für alle Standard-User ohne spezielle Rolle.
        if ($this->authChecker->isGranted('ROLE_AUDITOR')) {
            return self::TOUR_AUDITOR;
        }
        if ($this->authChecker->isGranted('ROLE_RISK_OWNER')) {
            return self::TOUR_RISK_OWNER;
        }
        if ($this->authChecker->isGranted('ROLE_COMPLIANCE_MANAGER')) {
            return self::TOUR_CM;
        }
        if ($this->authChecker->isGranted('ROLE_ISB')
            || $this->authChecker->isGranted('ROLE_MANAGER')
        ) {
            return self::TOUR_ISB;
        }
        if ($this->authChecker->isGranted('ROLE_GROUP_CISO')
            || $this->authChecker->isGranted('ROLE_ADMIN')
        ) {
            return self::TOUR_CISO;
        }
        return self::TOUR_JUNIOR;
    }

    /**
     * Step-Definition für eine Tour. Jeder Step referenziert
     * Translation-Keys, nicht ausformulierten Text.
     *
     * @return list<array{
     *   id: string,
     *   target: string|null,
     *   title_key: string,
     *   body_key: string,
     *   url: string|null,
     *   placement: string
     * }>
     */
    public function stepsFor(string $tourId): array
    {
        $base = match ($tourId) {
            self::TOUR_JUNIOR => $this->juniorSteps(),
            self::TOUR_CM => $this->cmSteps(),
            self::TOUR_CISO => $this->cisoSteps(),
            self::TOUR_ISB => $this->isbSteps(),
            self::TOUR_RISK_OWNER => $this->riskOwnerSteps(),
            self::TOUR_AUDITOR => $this->auditorSteps(),
            default => [],
        };

        // Modul-bedingte Zusatz-Stopps — nur für Junior und ISB sinnvoll
        // (operative Rollen, die mit den Domain-Modulen arbeiten).
        if (in_array($tourId, [self::TOUR_JUNIOR, self::TOUR_ISB], true)) {
            $base = array_merge($base, $this->moduleAddonSteps());
        }

        return $base;
    }

    /**
     * Extra-Stopps die nur hinzugefügt werden, wenn das jeweilige Modul
     * aktiv ist. Translation-Keys liegen unter `guided_tour.modules.*`.
     *
     * @return list<array{id: string, target: string|null, title_key: string, body_key: string, url: string|null, placement: string}>
     */
    private function moduleAddonSteps(): array
    {
        if ($this->moduleConfig === null) {
            return [];
        }

        $addons = [];
        if ($this->moduleConfig->isModuleActive('bsi_grundschutz')) {
            $addons[] = [
                'id' => 'module-bsi', 'icon' => 'bi-building-shield', 'target' => null,
                'title_key' => 'guided_tour.modules.bsi.title', 'body_key' => 'guided_tour.modules.bsi.body',
                'url' => null, 'placement' => 'center',
            ];
        }
        if ($this->moduleConfig->isModuleActive('privacy')
            || $this->moduleConfig->isModuleActive('gdpr')
        ) {
            $addons[] = [
                'id' => 'module-gdpr', 'icon' => 'bi-person-badge', 'target' => null,
                'title_key' => 'guided_tour.modules.gdpr.title', 'body_key' => 'guided_tour.modules.gdpr.body',
                'url' => null, 'placement' => 'center',
            ];
        }
        if ($this->moduleConfig->isModuleActive('bcm')) {
            $addons[] = [
                'id' => 'module-bcm', 'icon' => 'bi-life-preserver', 'target' => null,
                'title_key' => 'guided_tour.modules.bcm.title', 'body_key' => 'guided_tour.modules.bcm.body',
                'url' => null, 'placement' => 'center',
            ];
        }
        return $addons;
    }

    /** @return list<array{id: string, target: string|null, title_key: string, body_key: string, url: string|null, placement: string}> */
    private function juniorSteps(): array
    {
        $dashboard = $this->urlFor('app_dashboard');
        $assets = $this->urlFor('app_asset_index');
        return [
            ['id' => 'welcome', 'icon' => 'bi-stars', 'target' => null, 'title_key' => 'guided_tour.junior.step.welcome.title', 'body_key' => 'guided_tour.junior.step.welcome.body', 'url' => $dashboard, 'placement' => 'center'],
            ['id' => 'mega-menu', 'icon' => 'bi-grid-3x3-gap', 'target' => '[data-mega-menu-target="trigger"]', 'title_key' => 'guided_tour.junior.step.mega_menu.title', 'body_key' => 'guided_tour.junior.step.mega_menu.body', 'url' => $dashboard, 'placement' => 'bottom'],
            ['id' => 'kpis', 'icon' => 'bi-speedometer2', 'target' => '.management-kpis-widget, .dashboard-stats, [data-role="kpi-grid"]', 'title_key' => 'guided_tour.junior.step.kpis.title', 'body_key' => 'guided_tour.junior.step.kpis.body', 'url' => $dashboard, 'placement' => 'top'],
            ['id' => 'iso9001-bridge', 'icon' => 'bi-bridge', 'target' => null, 'title_key' => 'guided_tour.junior.step.iso9001_bridge.title', 'body_key' => 'guided_tour.junior.step.iso9001_bridge.body', 'url' => $dashboard, 'placement' => 'center'],
            ['id' => 'command-palette', 'icon' => 'bi-command', 'target' => null, 'title_key' => 'guided_tour.junior.step.command_palette.title', 'body_key' => 'guided_tour.junior.step.command_palette.body', 'url' => $dashboard, 'placement' => 'center'],
            ['id' => 'first-asset', 'icon' => 'bi-box-seam', 'target' => null, 'title_key' => 'guided_tour.junior.step.first_asset.title', 'body_key' => 'guided_tour.junior.step.first_asset.body', 'url' => $assets, 'placement' => 'center'],
            ['id' => 'shortcuts-hint', 'icon' => 'bi-keyboard', 'target' => null, 'title_key' => 'guided_tour.junior.step.shortcuts.title', 'body_key' => 'guided_tour.junior.step.shortcuts.body', 'url' => $dashboard, 'placement' => 'center'],
        ];
    }

    /** @return list<array{id: string, target: string|null, title_key: string, body_key: string, url: string|null, placement: string}> */
    private function cmSteps(): array
    {
        $dashboard = $this->urlFor('app_dashboard');
        $mapping = $this->urlFor('app_compliance_mapping_hub');
        $reuse = $this->urlFor('app_data_reuse_hub');
        return [
            ['id' => 'welcome', 'icon' => 'bi-stars', 'target' => null, 'title_key' => 'guided_tour.cm.step.welcome.title', 'body_key' => 'guided_tour.cm.step.welcome.body', 'url' => $dashboard, 'placement' => 'center'],
            ['id' => 'framework-dashboard', 'icon' => 'bi-speedometer2', 'target' => null, 'title_key' => 'guided_tour.cm.step.framework_dashboard.title', 'body_key' => 'guided_tour.cm.step.framework_dashboard.body', 'url' => $dashboard, 'placement' => 'center'],
            ['id' => 'mapping-hub', 'icon' => 'bi-diagram-3', 'target' => null, 'title_key' => 'guided_tour.cm.step.mapping_hub.title', 'body_key' => 'guided_tour.cm.step.mapping_hub.body', 'url' => $mapping, 'placement' => 'center'],
            ['id' => 'reuse-hub', 'icon' => 'bi-recycle', 'target' => null, 'title_key' => 'guided_tour.cm.step.reuse_hub.title', 'body_key' => 'guided_tour.cm.step.reuse_hub.body', 'url' => $reuse, 'placement' => 'center'],
            ['id' => 'seed-review', 'icon' => 'bi-check2-circle', 'target' => null, 'title_key' => 'guided_tour.cm.step.seed_review.title', 'body_key' => 'guided_tour.cm.step.seed_review.body', 'url' => $dashboard, 'placement' => 'center'],
        ];
    }

    /** @return list<array{id: string, icon: string|null, target: string|null, title_key: string, body_key: string, url: string|null, placement: string}> */
    private function cisoSteps(): array
    {
        $cisoDash = $this->urlFor('app_dashboard_ciso');
        $managementReports = $this->urlFor('app_management_reports');
        $frameworks = $this->urlFor('app_analytics_compliance_frameworks');
        return [
            ['id' => 'welcome', 'icon' => 'bi-shield-lock', 'target' => null, 'title_key' => 'guided_tour.ciso.step.welcome.title', 'body_key' => 'guided_tour.ciso.step.welcome.body', 'url' => $cisoDash, 'placement' => 'center'],
            ['id' => 'board-export', 'icon' => 'bi-file-earmark-slides', 'target' => null, 'title_key' => 'guided_tour.ciso.step.board_export.title', 'body_key' => 'guided_tour.ciso.step.board_export.body', 'url' => $managementReports, 'placement' => 'center'],
            ['id' => 'health-score', 'icon' => 'bi-heart-pulse', 'target' => null, 'title_key' => 'guided_tour.ciso.step.health_score.title', 'body_key' => 'guided_tour.ciso.step.health_score.body', 'url' => $cisoDash, 'placement' => 'center'],
            ['id' => 'framework-matrix', 'icon' => 'bi-grid-3x3', 'target' => null, 'title_key' => 'guided_tour.ciso.step.framework_matrix.title', 'body_key' => 'guided_tour.ciso.step.framework_matrix.body', 'url' => $frameworks, 'placement' => 'center'],
        ];
    }

    /** @return list<array{id: string, icon: string|null, target: string|null, title_key: string, body_key: string, url: string|null, placement: string}> */
    private function isbSteps(): array
    {
        $dashboard = $this->urlFor('app_dashboard');
        $soa = $this->urlFor('app_soa_index');
        $incidents = $this->urlFor('app_incident_index');
        $workflows = $this->urlFor('app_workflow_index');
        $auditLog = $this->urlFor('app_audit_log_index');
        return [
            ['id' => 'welcome', 'icon' => 'bi-stars', 'target' => null, 'title_key' => 'guided_tour.isb.step.welcome.title', 'body_key' => 'guided_tour.isb.step.welcome.body', 'url' => $dashboard, 'placement' => 'center'],
            ['id' => 'soa', 'icon' => 'bi-clipboard-check', 'target' => null, 'title_key' => 'guided_tour.isb.step.soa.title', 'body_key' => 'guided_tour.isb.step.soa.body', 'url' => $soa, 'placement' => 'center'],
            ['id' => 'incidents', 'icon' => 'bi-exclamation-triangle', 'target' => null, 'title_key' => 'guided_tour.isb.step.incidents.title', 'body_key' => 'guided_tour.isb.step.incidents.body', 'url' => $incidents, 'placement' => 'center'],
            ['id' => 'workflows', 'icon' => 'bi-diagram-2', 'target' => null, 'title_key' => 'guided_tour.isb.step.workflows.title', 'body_key' => 'guided_tour.isb.step.workflows.body', 'url' => $workflows, 'placement' => 'center'],
            ['id' => 'audit-log', 'icon' => 'bi-journal-text', 'target' => null, 'title_key' => 'guided_tour.isb.step.audit_log.title', 'body_key' => 'guided_tour.isb.step.audit_log.body', 'url' => $auditLog, 'placement' => 'center'],
        ];
    }

    /** @return list<array{id: string, icon: string|null, target: string|null, title_key: string, body_key: string, url: string|null, placement: string}> */
    private function riskOwnerSteps(): array
    {
        $dashboard = $this->urlFor('app_dashboard');
        $risks = $this->urlFor('app_risk_index');
        return [
            ['id' => 'welcome', 'icon' => 'bi-stars', 'target' => null, 'title_key' => 'guided_tour.risk_owner.step.welcome.title', 'body_key' => 'guided_tour.risk_owner.step.welcome.body', 'url' => $dashboard, 'placement' => 'center'],
            ['id' => 'my-risks', 'icon' => 'bi-exclamation-diamond', 'target' => null, 'title_key' => 'guided_tour.risk_owner.step.my_risks.title', 'body_key' => 'guided_tour.risk_owner.step.my_risks.body', 'url' => $risks, 'placement' => 'center'],
        ];
    }

    /** @return list<array{id: string, icon: string|null, target: string|null, title_key: string, body_key: string, url: string|null, placement: string}> */
    private function auditorSteps(): array
    {
        $auditorDash = $this->urlFor('app_dashboard_auditor');
        $documents = $this->urlFor('app_document_index');
        $auditLog = $this->urlFor('app_audit_log_index');
        return [
            ['id' => 'welcome', 'icon' => 'bi-search', 'target' => null, 'title_key' => 'guided_tour.auditor.step.welcome.title', 'body_key' => 'guided_tour.auditor.step.welcome.body', 'url' => $auditorDash, 'placement' => 'center'],
            ['id' => 'documents', 'icon' => 'bi-file-earmark-text', 'target' => null, 'title_key' => 'guided_tour.auditor.step.documents.title', 'body_key' => 'guided_tour.auditor.step.documents.body', 'url' => $documents, 'placement' => 'center'],
            ['id' => 'audit-log', 'icon' => 'bi-journal-text', 'target' => null, 'title_key' => 'guided_tour.auditor.step.audit_log.title', 'body_key' => 'guided_tour.auditor.step.audit_log.body', 'url' => $auditLog, 'placement' => 'center'],
        ];
    }

    /**
     * Metadaten für eine Tour (Anzahl Steps, ungefähre Dauer).
     * Für Banner, Launcher, Completion-Report.
     *
     * @return array{id: string, label_key: string, description_key: string, step_count: int, duration_min: int}
     */
    public function metaFor(string $tourId): array
    {
        $steps = $this->stepsFor($tourId);
        return [
            'id' => $tourId,
            'label_key' => "guided_tour.{$tourId}.meta.label",
            'description_key' => "guided_tour.{$tourId}.meta.description",
            'step_count' => count($steps),
            'duration_min' => match ($tourId) {
                self::TOUR_JUNIOR => 5,
                self::TOUR_CM => 3,
                self::TOUR_CISO => 2,
                self::TOUR_ISB => 4,
                self::TOUR_RISK_OWNER => 1,
                self::TOUR_AUDITOR => 2,
                default => 3,
            },
        ];
    }

    /**
     * Liste aller verfügbaren Touren mit Meta — für Role-Picker.
     *
     * @return list<array{id: string, label_key: string, description_key: string, step_count: int, duration_min: int}>
     */
    public function allMeta(): array
    {
        $out = [];
        foreach (self::ALL_TOURS as $tourId) {
            $out[] = $this->metaFor($tourId);
        }
        return $out;
    }
}
