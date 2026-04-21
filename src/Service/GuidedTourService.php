<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

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
    ) {
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
        return match ($tourId) {
            self::TOUR_JUNIOR => $this->juniorSteps(),
            self::TOUR_CM => $this->cmSteps(),
            self::TOUR_CISO => $this->cisoSteps(),
            self::TOUR_ISB => $this->isbSteps(),
            self::TOUR_RISK_OWNER => $this->riskOwnerSteps(),
            self::TOUR_AUDITOR => $this->auditorSteps(),
            default => [],
        };
    }

    /** @return list<array{id: string, target: string|null, title_key: string, body_key: string, url: string|null, placement: string}> */
    private function juniorSteps(): array
    {
        return [
            [
                'id' => 'welcome',
                'target' => null,
                'title_key' => 'guided_tour.junior.step.welcome.title',
                'body_key' => 'guided_tour.junior.step.welcome.body',
                'url' => null,
                'placement' => 'center',
            ],
            [
                'id' => 'mega-menu',
                'target' => '[data-mega-menu-target="trigger"]',
                'title_key' => 'guided_tour.junior.step.mega_menu.title',
                'body_key' => 'guided_tour.junior.step.mega_menu.body',
                'url' => null,
                'placement' => 'bottom',
            ],
            [
                'id' => 'kpis',
                'target' => '.management-kpis-widget, .dashboard-stats, [data-role="kpi-grid"]',
                'title_key' => 'guided_tour.junior.step.kpis.title',
                'body_key' => 'guided_tour.junior.step.kpis.body',
                'url' => null,
                'placement' => 'top',
            ],
            [
                'id' => 'iso9001-bridge',
                'target' => null,
                'title_key' => 'guided_tour.junior.step.iso9001_bridge.title',
                'body_key' => 'guided_tour.junior.step.iso9001_bridge.body',
                'url' => null,
                'placement' => 'center',
            ],
            [
                'id' => 'command-palette',
                'target' => null,
                'title_key' => 'guided_tour.junior.step.command_palette.title',
                'body_key' => 'guided_tour.junior.step.command_palette.body',
                'url' => null,
                'placement' => 'center',
            ],
            [
                'id' => 'first-asset',
                'target' => null,
                'title_key' => 'guided_tour.junior.step.first_asset.title',
                'body_key' => 'guided_tour.junior.step.first_asset.body',
                'url' => null,
                'placement' => 'center',
            ],
            [
                'id' => 'shortcuts-hint',
                'target' => null,
                'title_key' => 'guided_tour.junior.step.shortcuts.title',
                'body_key' => 'guided_tour.junior.step.shortcuts.body',
                'url' => null,
                'placement' => 'center',
            ],
        ];
    }

    /** @return list<array{id: string, target: string|null, title_key: string, body_key: string, url: string|null, placement: string}> */
    private function cmSteps(): array
    {
        return [
            ['id' => 'welcome', 'target' => null, 'title_key' => 'guided_tour.cm.step.welcome.title', 'body_key' => 'guided_tour.cm.step.welcome.body', 'url' => null, 'placement' => 'center'],
            ['id' => 'framework-dashboard', 'target' => null, 'title_key' => 'guided_tour.cm.step.framework_dashboard.title', 'body_key' => 'guided_tour.cm.step.framework_dashboard.body', 'url' => null, 'placement' => 'center'],
            ['id' => 'mapping-hub', 'target' => null, 'title_key' => 'guided_tour.cm.step.mapping_hub.title', 'body_key' => 'guided_tour.cm.step.mapping_hub.body', 'url' => null, 'placement' => 'center'],
            ['id' => 'reuse-hub', 'target' => null, 'title_key' => 'guided_tour.cm.step.reuse_hub.title', 'body_key' => 'guided_tour.cm.step.reuse_hub.body', 'url' => null, 'placement' => 'center'],
            ['id' => 'seed-review', 'target' => null, 'title_key' => 'guided_tour.cm.step.seed_review.title', 'body_key' => 'guided_tour.cm.step.seed_review.body', 'url' => null, 'placement' => 'center'],
        ];
    }

    /** @return list<array{id: string, target: string|null, title_key: string, body_key: string, url: string|null, placement: string}> */
    private function cisoSteps(): array
    {
        return [
            ['id' => 'welcome', 'target' => null, 'title_key' => 'guided_tour.ciso.step.welcome.title', 'body_key' => 'guided_tour.ciso.step.welcome.body', 'url' => null, 'placement' => 'center'],
            ['id' => 'board-export', 'target' => null, 'title_key' => 'guided_tour.ciso.step.board_export.title', 'body_key' => 'guided_tour.ciso.step.board_export.body', 'url' => null, 'placement' => 'center'],
            ['id' => 'health-score', 'target' => null, 'title_key' => 'guided_tour.ciso.step.health_score.title', 'body_key' => 'guided_tour.ciso.step.health_score.body', 'url' => null, 'placement' => 'center'],
            ['id' => 'framework-matrix', 'target' => null, 'title_key' => 'guided_tour.ciso.step.framework_matrix.title', 'body_key' => 'guided_tour.ciso.step.framework_matrix.body', 'url' => null, 'placement' => 'center'],
        ];
    }

    /** @return list<array{id: string, target: string|null, title_key: string, body_key: string, url: string|null, placement: string}> */
    private function isbSteps(): array
    {
        return [
            ['id' => 'welcome', 'target' => null, 'title_key' => 'guided_tour.isb.step.welcome.title', 'body_key' => 'guided_tour.isb.step.welcome.body', 'url' => null, 'placement' => 'center'],
            ['id' => 'soa', 'target' => null, 'title_key' => 'guided_tour.isb.step.soa.title', 'body_key' => 'guided_tour.isb.step.soa.body', 'url' => null, 'placement' => 'center'],
            ['id' => 'incidents', 'target' => null, 'title_key' => 'guided_tour.isb.step.incidents.title', 'body_key' => 'guided_tour.isb.step.incidents.body', 'url' => null, 'placement' => 'center'],
            ['id' => 'workflows', 'target' => null, 'title_key' => 'guided_tour.isb.step.workflows.title', 'body_key' => 'guided_tour.isb.step.workflows.body', 'url' => null, 'placement' => 'center'],
            ['id' => 'audit-log', 'target' => null, 'title_key' => 'guided_tour.isb.step.audit_log.title', 'body_key' => 'guided_tour.isb.step.audit_log.body', 'url' => null, 'placement' => 'center'],
        ];
    }

    /** @return list<array{id: string, target: string|null, title_key: string, body_key: string, url: string|null, placement: string}> */
    private function riskOwnerSteps(): array
    {
        return [
            ['id' => 'welcome', 'target' => null, 'title_key' => 'guided_tour.risk_owner.step.welcome.title', 'body_key' => 'guided_tour.risk_owner.step.welcome.body', 'url' => null, 'placement' => 'center'],
            ['id' => 'my-risks', 'target' => null, 'title_key' => 'guided_tour.risk_owner.step.my_risks.title', 'body_key' => 'guided_tour.risk_owner.step.my_risks.body', 'url' => null, 'placement' => 'center'],
        ];
    }

    /** @return list<array{id: string, target: string|null, title_key: string, body_key: string, url: string|null, placement: string}> */
    private function auditorSteps(): array
    {
        return [
            ['id' => 'welcome', 'target' => null, 'title_key' => 'guided_tour.auditor.step.welcome.title', 'body_key' => 'guided_tour.auditor.step.welcome.body', 'url' => null, 'placement' => 'center'],
            ['id' => 'documents', 'target' => null, 'title_key' => 'guided_tour.auditor.step.documents.title', 'body_key' => 'guided_tour.auditor.step.documents.body', 'url' => null, 'placement' => 'center'],
            ['id' => 'audit-log', 'target' => null, 'title_key' => 'guided_tour.auditor.step.audit_log.title', 'body_key' => 'guided_tour.auditor.step.audit_log.body', 'url' => null, 'placement' => 'center'],
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
