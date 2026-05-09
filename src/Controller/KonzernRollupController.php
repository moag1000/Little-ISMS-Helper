<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\PolicyWizardVoter;
use App\Service\PolicyWizard\Rollup\KonzernRollupAggregator;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Policy-Wizard W7-B — Konzern Roll-Up dashboard.
 *
 * Read-only HTTP surface for the holding-level CISO / Group-Compliance
 * Officer / Konzern-Auditor. Aggregates six dashboard slices (coverage,
 * actions, compliance, drift, acknowledgement) over the entire Konzern
 * subtree via {@see KonzernRollupAggregator}.
 *
 * Spec: docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md
 *       lines 298-301 (Compliance-Manager "What's missing" #4 +
 *       CISO Board-Reporting + Auditor Konzern-Tochter compliance).
 *
 * Voter: {@see PolicyWizardVoter::KONZERN_DEFAULTS} — accessible to
 * ROLE_GROUP_CISO, ROLE_GROUP_BCM_OFFICER and ROLE_SUPER_ADMIN. The
 * voter additionally enforces the holding-tree scope.
 */
#[Route('/policy-wizard/konzern-rollup', name: 'app_policy_wizard_konzern_rollup_')]
final class KonzernRollupController extends AbstractController
{
    public function __construct(
        private readonly KonzernRollupAggregator $aggregator,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Render the roll-up dashboard. Auto-detects the Konzern root:
     *  1. The current tenant if it has subsidiaries (regardless of
     *     whether it itself has ancestors — handy for nested holdings).
     *  2. Otherwise the first ancestor that has subsidiaries.
     *  3. Otherwise renders the "not_konzern" empty state.
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            // Should not happen — security firewall covers this.
            return $this->redirectToRoute('app_login');
        }

        $currentTenant = $this->tenantContext->getCurrentTenant();
        if (!$currentTenant instanceof Tenant) {
            $this->addFlash('warning', $this->translator->trans('policy_wizard.error.no_tenant', [], 'policy_wizard'));
            return $this->redirectToRoute('app_dashboard');
        }

        $konzernRoot = $this->resolveKonzernRoot($currentTenant);

        if (!$konzernRoot instanceof Tenant) {
            // Render the dashboard shell with the "not a Konzern" empty
            // state so the navigation breadcrumb stays consistent.
            return $this->render('policy_wizard/konzern_rollup/index.html.twig', [
                'report'              => null,
                'konzern_root'        => null,
                'current_tenant'      => $currentTenant,
                'not_a_konzern'       => true,
            ]);
        }

        // Voter enforces ROLE_GROUP_CISO / ROLE_GROUP_BCM_OFFICER /
        // ROLE_SUPER_ADMIN + holding-tree scope.
        $this->denyAccessUnlessGranted(PolicyWizardVoter::KONZERN_DEFAULTS, $konzernRoot);

        $report = $this->aggregator->aggregateForKonzern($konzernRoot);

        return $this->render('policy_wizard/konzern_rollup/index.html.twig', [
            'report'         => $report,
            'konzern_root'   => $konzernRoot,
            'current_tenant' => $currentTenant,
            'not_a_konzern'  => false,
        ]);
    }

    /**
     * Walk the tenant + ancestors chain, returning the first node that
     * has at least one subsidiary. Returns null when no such node exists
     * (i.e. the user sits in a standalone tenant or pure leaf node with
     * no holding context).
     */
    private function resolveKonzernRoot(Tenant $startingTenant): ?Tenant
    {
        if ($startingTenant->getSubsidiaries()->count() > 0) {
            return $startingTenant;
        }
        foreach ($startingTenant->getAllAncestors() as $ancestor) {
            if (!$ancestor instanceof Tenant) {
                continue;
            }
            if ($ancestor->getSubsidiaries()->count() > 0) {
                return $ancestor;
            }
        }
        return null;
    }
}
