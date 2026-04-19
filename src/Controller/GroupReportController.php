<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Tenant;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Phase 9.P1.7 + P1.8 — Group-level read-only reports intended for a
 * Konzern-ISB / Group-CISO sitting at a holding tenant. Access requires
 * ROLE_GROUP_CISO and at least one subsidiary below the current tenant.
 *
 * Why a separate controller: the per-tenant views (risk index,
 * incident list, SoA, ...) are driven by the TenantContext of the
 * logged-in user. The group reports instead traverse the subtree
 * explicitly and never mutate data.
 */
#[Route('/group-report', name: 'app_group_report_')]
#[IsGranted('ROLE_GROUP_CISO')]
final class GroupReportController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/nis2-registration', name: 'nis2_registration', methods: ['GET'])]
    public function nis2Registration(): Response
    {
        $root = $this->tenantContext->getCurrentTenant();
        if (!$root instanceof Tenant) {
            throw $this->createAccessDeniedException('No active tenant');
        }

        $tree = $this->tenantContext->getAccessibleTenants();
        $stats = [
            'total' => count($tree),
            'essential' => 0,
            'important' => 0,
            'not_regulated' => 0,
            'unknown' => 0,
            'registered' => 0,
        ];
        foreach ($tree as $tenant) {
            $class = $tenant->getNis2Classification() ?? Tenant::NIS2_UNKNOWN;
            if (isset($stats[$class])) {
                $stats[$class]++;
            } else {
                $stats['unknown']++;
            }
            if ($tenant->getNis2RegisteredAt() !== null) {
                $stats['registered']++;
            }
        }

        return $this->render('group_report/nis2_registration.html.twig', [
            'root' => $root,
            'tenants' => $tree,
            'stats' => $stats,
        ]);
    }

    #[Route('/tree', name: 'tree', methods: ['GET'])]
    public function tree(): Response
    {
        $root = $this->tenantContext->getCurrentTenant();
        if (!$root instanceof Tenant) {
            throw $this->createAccessDeniedException('No active tenant');
        }

        // Intentionally NOT getRootParent(): a Group-CISO sitting on a
        // mid-tree tenant (e.g. a regional holding) must see only their
        // subtree — lateral access to sibling subsidiaries and upward
        // access to the top holding is a segregation violation.
        return $this->render('group_report/tree.html.twig', [
            'root' => $root,
            'current' => $root,
        ]);
    }
}
