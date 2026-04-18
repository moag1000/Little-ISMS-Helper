<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ImportSession;
use App\Repository\ImportRowEventRepository;
use App\Repository\ImportSessionRepository;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ISB-Review Sprint-2 gate MINOR-1: auditor-facing UI for the per-row import
 * audit trail (see docs/DATA_REUSE_PLAN_REVIEW_ISB.md).
 *
 * Uses {@see ImportSessionRepository} for the session list and
 * {@see ImportRowEventRepository} for the per-session row detail view
 * (filterable by decision / target entity type).
 *
 * RBAC: the plan asks for ROLE_MANAGER. The application-wide firewall
 * (config/packages/security.yaml) requires ROLE_ADMIN for every `/admin/*`
 * path, so the effective gate is ROLE_ADMIN (which inherits ROLE_MANAGER
 * via role_hierarchy). The #[IsGranted] keeps intent explicit for readers
 * and protects sub-routes should the firewall be relaxed in future.
 */
#[IsGranted('ROLE_MANAGER')]
#[Route(
    path: '/admin/import/history',
    name: 'admin_import_history_'
)]
final class ImportHistoryController extends AbstractController
{
    private const PAGE_SIZE_SESSIONS = 20;
    private const PAGE_SIZE_EVENTS = 50;

    public function __construct(
        private readonly ImportSessionRepository $importSessionRepository,
        private readonly ImportRowEventRepository $importRowEventRepository,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createAccessDeniedException('No tenant context.');
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $offset = ($page - 1) * self::PAGE_SIZE_SESSIONS;

        $total = $this->importSessionRepository->countByTenant($tenant);
        $sessions = $this->importSessionRepository->findByTenantPaginated(
            $tenant,
            self::PAGE_SIZE_SESSIONS,
            $offset,
        );

        return $this->render('admin/compliance_import/history/index.html.twig', [
            'sessions' => $sessions,
            'total' => $total,
            'page' => $page,
            'page_size' => self::PAGE_SIZE_SESSIONS,
            'page_count' => (int) max(1, ceil($total / self::PAGE_SIZE_SESSIONS)),
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(int $id, Request $request): Response
    {
        $session = $this->importSessionRepository->find($id);
        if (!$session instanceof ImportSession) {
            throw $this->createNotFoundException('Import session not found.');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null || $session->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('Import session not in current tenant.');
        }

        $decision = trim((string) $request->query->get('decision', ''));
        $targetEntityType = trim((string) $request->query->get('target_entity_type', ''));
        $searchEntityId = trim((string) $request->query->get('search_entity_id', ''));

        $page = max(1, (int) $request->query->get('page', 1));
        $offset = ($page - 1) * self::PAGE_SIZE_EVENTS;

        // Search box → findByTarget: if the auditor supplies both an entity
        // type and an entity id, render only those rows (newest first).
        $searchResults = null;
        if ($targetEntityType !== '' && $searchEntityId !== '' && ctype_digit($searchEntityId)) {
            $searchResults = $this->importRowEventRepository->findByTarget(
                $targetEntityType,
                (int) $searchEntityId,
                self::PAGE_SIZE_EVENTS,
            );
        }

        $events = $searchResults ?? $this->importRowEventRepository->findBySessionPaginated(
            $session,
            self::PAGE_SIZE_EVENTS,
            $offset,
            $decision !== '' ? $decision : null,
            $targetEntityType !== '' ? $targetEntityType : null,
        );

        $totalFiltered = $searchResults !== null
            ? count($searchResults)
            : $this->importRowEventRepository->countBySessionFiltered(
                $session,
                $decision !== '' ? $decision : null,
                $targetEntityType !== '' ? $targetEntityType : null,
            );

        return $this->render('admin/compliance_import/history/show.html.twig', [
            'session' => $session,
            'events' => $events,
            'total' => $totalFiltered,
            'page' => $page,
            'page_size' => self::PAGE_SIZE_EVENTS,
            'page_count' => (int) max(1, ceil($totalFiltered / self::PAGE_SIZE_EVENTS)),
            'filter' => [
                'decision' => $decision,
                'target_entity_type' => $targetEntityType,
                'search_entity_id' => $searchEntityId,
            ],
            'is_search' => $searchResults !== null,
        ]);
    }
}
