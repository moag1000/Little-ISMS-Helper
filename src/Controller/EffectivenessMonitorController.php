<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Service\AuditLogger;
use App\Service\ControlEffectivenessMonitor;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Wirksamkeits-Monitor (Control Effectiveness Monitor).
 *
 * ISO 27001 §9.1 / Annex A 5.35/5.36 — dedicated view for ROLE_AUDITOR+
 * that surfaces controls whose effectiveness-check cadence is overdue,
 * coming due, or recently confirmed.
 *
 * Route: /{locale}/effectiveness-monitor
 * Gate:  ROLE_AUDITOR (ISB, Compliance-Manager, CISO, Admin inherit)
 *        + module `controls` active
 */
#[Route('/effectiveness-monitor', name: 'app_effectiveness_monitor')]
#[IsGranted('ROLE_AUDITOR')]
class EffectivenessMonitorController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly ControlEffectivenessMonitor $monitor,
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ?AuditLogger $auditLogger = null,
    ) {
    }

    /**
     * Main dashboard of the Wirksamkeits-Monitor.
     *
     * Query params:
     *   - category (string) — ISO 27001 category prefix filter (e.g. "5.", "6.", "7.", "8.")
     *   - threshold (int)   — overdue-month threshold (default 12)
     */
    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('controls')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null) {
            return $this->render('effectiveness_monitor/index.html.twig', [
                'stats'              => null,
                'overdueControls'    => [],
                'upcomingControls'   => [],
                'recentlyChecked'    => [],
                'categoryFilter'     => null,
                'thresholdMonths'    => 12,
                'noTenant'           => true,
            ]);
        }

        $categoryFilter  = $request->query->get('category');
        $thresholdMonths = max(1, (int) ($request->query->get('threshold', '12')));

        $stats           = $this->monitor->calculateSummaryStats($tenant);
        $overdueControls = $this->monitor->findOverdueControls($tenant, $thresholdMonths);
        $upcomingControls = $this->monitor->findUpcomingDueControls($tenant);
        $recentlyChecked  = $this->monitor->findRecentlyChecked($tenant);

        // Apply optional category filter to overdue list
        if ($categoryFilter !== null && $categoryFilter !== '') {
            $overdueControls = array_filter(
                $overdueControls,
                static fn(array $row): bool => str_starts_with(
                    $row['control']->getCategory() ?? '',
                    $categoryFilter
                )
            );
            $overdueControls = array_values($overdueControls);
        }

        return $this->render('effectiveness_monitor/index.html.twig', [
            'stats'              => $stats,
            'overdueControls'    => $overdueControls,
            'upcomingControls'   => $upcomingControls,
            'recentlyChecked'    => $recentlyChecked,
            'categoryFilter'     => $categoryFilter,
            'thresholdMonths'    => $thresholdMonths,
            'noTenant'           => false,
        ]);
    }
}
