<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditFinding;
use App\Entity\InternalAudit;
use App\Entity\Tenant;
use App\Form\AuditFindingType;
use App\Repository\AuditFindingRepository;
use App\Repository\CommentRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\Clone\AuditFindingCloner;
use App\Service\Nonconformity\AutoTaskCreator;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * H-01: CRUD for structured Audit Findings (ISO 27001 Clause 10.1).
 */
#[IsGranted('ROLE_USER')]
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/audit-finding', name: 'app_audit_finding_')]
class AuditFindingController extends AbstractController
{
    public function __construct(
        private readonly AuditFindingRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly AutoTaskCreator $autoTaskCreator,
        private readonly Security $security,
        private readonly UserRepository $userRepository,
        private readonly InternalAuditRepository $internalAuditRepository,
        private readonly ?CommentRepository $commentRepository = null,
        private readonly ?AuditFindingCloner $auditFindingCloner = null,
    ) {
    }

    /**
     * S17 B4 follow-up — choices payload for the CAPA-Builder owner-picker.
     * Returns active tenant users as `[{id, label}, ...]` so the Stimulus
     * controller can hydrate the corrective-action owner dropdown without
     * an extra XHR roundtrip.
     *
     * @return list<array{id: int, label: string}>
     */
    private function getNcUserChoices(): array
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }
        $users = $this->userRepository->createQueryBuilder('u')
            ->andWhere('u.tenant = :tenant')
            ->andWhere('u.isActive = :active')
            ->setParameter('tenant', $tenant)
            ->setParameter('active', true)
            ->orderBy('u.lastName', 'ASC')
            ->addOrderBy('u.firstName', 'ASC')
            ->getQuery()
            ->getResult();
        $choices = [];
        foreach ($users as $u) {
            $id = $u->getId();
            if ($id === null) {
                continue;
            }
            $choices[] = ['id' => $id, 'label' => $u->getFullName()];
        }
        return $choices;
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $status = $request->query->get('status');
        $severity = $request->query->get('severity');
        // Alva-hint deep-link target: pre-filter to exactly the set a hint counts.
        $focus = $request->query->get('focus');

        $criteria = [];
        if ($tenant instanceof Tenant) {
            $criteria['tenant'] = $tenant;
        }
        if ($status) {
            $criteria['status'] = $status;
        }
        if ($severity) {
            $criteria['severity'] = $severity;
        }

        $overdue = $tenant instanceof Tenant ? $this->repository->findOverdue($tenant) : [];

        if ($tenant instanceof Tenant && $focus === 'overdue') {
            $findings = $overdue;
        } elseif ($tenant instanceof Tenant && $focus === 'nc_unreferenced') {
            $findings = $this->repository->findOpenWithoutRequirements($tenant);
        } else {
            $findings = $this->repository->findBy($criteria, ['createdAt' => 'DESC']);
        }

        return $this->render('audit_finding/index.html.twig', [
            'findings' => $findings,
            'overdue_count' => count($overdue),
            'selected_status' => $status,
            'selected_severity' => $severity,
            'focus' => $focus,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $finding = new AuditFinding();
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant instanceof Tenant) {
            $finding->setTenant($tenant);
        }

        // F14 — pre-link to parent audit when ?audit=<id> is provided (e.g. from audit show-page).
        // Validate tenant scope before accepting the param.
        $prelinkedAudit = $this->resolveAuditParam($request, $tenant);
        $auditLocked = false;
        if ($prelinkedAudit instanceof InternalAudit) {
            $finding->setAudit($prelinkedAudit);
            $auditLocked = true;
        }

        $form = $this->createForm(AuditFindingType::class, $finding, [
            'audit_locked' => $auditLocked,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($finding);
            $this->entityManager->flush();

            // F15.3 — auto-create CorrectiveAction tasks for linked requirements.
            $this->autoTaskCreator->createTasksForLinkedRequirements($finding);

            $this->auditLogger->logCreate(
                'AuditFinding',
                $finding->getId(),
                ['title' => $finding->getTitle(), 'severity' => $finding->getSeverity()],
                'AuditFinding created'
            );

            $this->addFlash('success', 'audit_finding.flash.created');

            // F14 — when pre-linked to an audit, redirect back to the audit show-page.
            if ($prelinkedAudit instanceof InternalAudit) {
                return $this->redirectToRoute('app_audit_show', ['id' => $prelinkedAudit->getId()]);
            }

            return $this->redirectToRoute('app_audit_finding_show', ['id' => $finding->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('audit_finding/new.html.twig', [
            'form' => $form,
            'nc_user_choices' => $this->getNcUserChoices(),
            'prelinked_audit' => $prelinkedAudit,
        ], new Response(status: $status));
    }

    /**
     * F14 — Quick inline finding capture from the audit show-page modal.
     *
     * Accepts a lean POST payload (title, type, severity, description, audit_id)
     * and creates a minimal AuditFinding pre-linked to the parent audit.
     * On success redirects back to the audit show-page so context is preserved.
     * The full-detail edit is available from the finding's own show-page.
     */
    #[Route('/new-quick', name: 'new_quick', methods: ['POST'])]
    public function newQuick(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createAccessDeniedException('No tenant context.');
        }

        if (!$this->isCsrfTokenValid('quick_finding', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $auditId = (int) $request->request->get('audit_id');
        $audit = $this->internalAuditRepository->find($auditId);
        if (!$audit instanceof InternalAudit || $audit->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Audit not found or access denied.');
        }

        $title = trim((string) $request->request->get('title', ''));
        if ($title === '') {
            $this->addFlash('danger', 'audit_finding.flash.quick_title_required');
            return $this->redirectToRoute('app_audit_show', ['id' => $auditId]);
        }

        $finding = new AuditFinding();
        $finding->setTenant($tenant);
        $finding->setAudit($audit);
        $finding->setTitle($title);

        $type = (string) $request->request->get('type', AuditFinding::TYPE_MINOR_NC);
        $allowedTypes = [
            AuditFinding::TYPE_MAJOR_NC,
            AuditFinding::TYPE_MINOR_NC,
            AuditFinding::TYPE_OBSERVATION,
            AuditFinding::TYPE_OPPORTUNITY,
        ];
        if (!in_array($type, $allowedTypes, true)) {
            $type = AuditFinding::TYPE_MINOR_NC;
        }
        $finding->setType($type);

        $severity = (string) $request->request->get('severity', AuditFinding::SEVERITY_MEDIUM);
        $allowedSeverities = [
            AuditFinding::SEVERITY_CRITICAL,
            AuditFinding::SEVERITY_HIGH,
            AuditFinding::SEVERITY_MEDIUM,
            AuditFinding::SEVERITY_LOW,
        ];
        if (!in_array($severity, $allowedSeverities, true)) {
            $severity = AuditFinding::SEVERITY_MEDIUM;
        }
        $finding->setSeverity($severity);

        $description = trim((string) $request->request->get('description', ''));
        if ($description !== '') {
            $finding->setDescription($description);
        } else {
            // description is NOT NULL in the DB; use a sentinel that the user can expand later.
            $finding->setDescription($title);
        }

        $this->entityManager->persist($finding);
        $this->entityManager->flush();

        $this->auditLogger->logCreate(
            'AuditFinding',
            $finding->getId(),
            ['title' => $finding->getTitle(), 'severity' => $finding->getSeverity(), 'source' => 'quick-capture'],
            'AuditFinding created via quick-capture modal'
        );

        $this->addFlash('success', 'audit_finding.flash.created');
        return $this->redirectToRoute('app_audit_show', ['id' => $audit->getId()]);
    }

    /**
     * F14 — resolve and validate the ?audit=<id> query parameter.
     * Returns the InternalAudit only when it belongs to the current tenant
     * (or the current user is ROLE_ADMIN). Returns null when param is absent
     * or the lookup fails — callers treat null as "no pre-link requested".
     */
    private function resolveAuditParam(Request $request, ?Tenant $tenant): ?InternalAudit
    {
        $auditId = $request->query->getInt('audit');
        if ($auditId <= 0) {
            return null;
        }
        $audit = $this->internalAuditRepository->find($auditId);
        if (!$audit instanceof InternalAudit) {
            return null;
        }
        // Tenant-scope guard: accept if tenant matches or user is admin.
        if ($tenant instanceof Tenant && $audit->getTenant()?->getId() === $tenant->getId()) {
            return $audit;
        }
        if ($this->isGranted('ROLE_ADMIN')) {
            return $audit;
        }
        return null;
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(AuditFinding $finding): Response
    {
        $this->denyIfWrongTenant($finding);

        // V3 W2-H3: Comment-Thread (C7) — load thread for this AuditFinding.
        $tenant = $this->tenantContext->getCurrentTenant();
        $comments = [];
        if ($this->commentRepository !== null && $tenant instanceof Tenant && $finding->getId() !== null) {
            $comments = $this->commentRepository->findThread($tenant, 'AuditFinding', $finding->getId());
        }

        return $this->render('audit_finding/show.html.twig', [
            'finding' => $finding,
            // V3 W2-H3: Comments thread + form action
            'comments' => $comments,
        ]);
    }

    /**
     * Clone an AuditFinding (C4-C1 — Klon-Funktionen). Open to ROLE_USER.
     * Keeps finding template (type, severity, clause reference, related
     * controls, linked requirements), resets lifecycle + nc-verification.
     */
    #[Route('/{id}/clone', name: 'clone', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function clone(Request $request, AuditFinding $finding): Response
    {
        $this->denyIfWrongTenant($finding);

        if (!$this->isCsrfTokenValid('clone_audit_finding_' . $finding->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if ($this->auditFindingCloner === null) {
            throw $this->createNotFoundException('AuditFinding clone service is not available.');
        }

        $clone = $this->auditFindingCloner->clone(
            $finding,
            null,
            trim((string) $request->request->get('title_override', '')) ?: null,
        );
        $this->entityManager->flush();

        $this->auditLogger->logCreate(
            entityType: 'AuditFinding',
            entityId: $clone->getId(),
            newValues: ['cloned_from_id' => $finding->getId(), 'title' => $clone->getTitle()],
            description: 'Cloned from AuditFinding #' . $finding->getId(),
        );

        $this->addFlash('success', 'audit_finding.clone.success');
        return $this->redirectToRoute('app_audit_finding_edit', ['id' => $clone->getId()]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, AuditFinding $finding): Response
    {
        $this->denyIfWrongTenant($finding);

        $form = $this->createForm(AuditFindingType::class, $finding);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            // F15.3 — auto-create CorrectiveAction tasks for linked requirements (idempotent).
            $this->autoTaskCreator->createTasksForLinkedRequirements($finding);

            $this->auditLogger->logUpdate(
                'AuditFinding',
                $finding->getId(),
                [],
                ['status' => $finding->getStatus(), 'severity' => $finding->getSeverity()],
                'AuditFinding updated'
            );

            $this->addFlash('success', 'audit_finding.flash.updated');
            return $this->redirectToRoute('app_audit_finding_show', ['id' => $finding->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('audit_finding/edit.html.twig', [
            'finding' => $finding,
            'form' => $form,
            'nc_user_choices' => $this->getNcUserChoices(),
        ], new Response(status: $status));
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, AuditFinding $finding): Response
    {
        $this->denyIfWrongTenant($finding);

        if (!$this->isCsrfTokenValid('delete_af_' . $finding->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $id = $finding->getId();
        $this->auditLogger->logDelete('AuditFinding', $id, ['title' => $finding->getTitle()], 'AuditFinding deleted');

        $this->entityManager->remove($finding);
        $this->entityManager->flush();

        $this->addFlash('success', 'audit_finding.flash.deleted');
        return $this->redirectToRoute('app_audit_finding_index');
    }

    private function denyIfWrongTenant(AuditFinding $finding): void
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant || $finding->getTenant()?->getId() !== $tenant->getId()) {
            if (!$this->isGranted('ROLE_ADMIN')) {
                throw $this->createAccessDeniedException('Finding belongs to a different tenant.');
            }
        }
    }

    /**
     * Dependency-check endpoint for the Aurora bulk-delete-confirmation modal.
     * AuditFindings have no blocking FK relations — returns empty dependencies.
     */
    #[Route('/bulk-delete-check', name: 'bulk_delete_check', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDeleteCheck(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $ids = (array) ($data['ids'] ?? []);
        return new JsonResponse(['dependencies' => [], 'checked_count' => count($ids)]);
    }

    #[Route('/bulk-delete', name: 'bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $tenant = $this->security->getUser()?->getTenant();
        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $finding = $this->repository->find($id);
                if (!$finding) {
                    $errors[] = "AuditFinding ID $id not found";
                    continue;
                }
                if ($tenant && $finding->getTenant() !== $tenant) {
                    $errors[] = "AuditFinding ID $id does not belong to your organization";
                    continue;
                }
                $this->entityManager->remove($finding);
                $deleted++;
            } catch (Exception $e) {
                $errors[] = "Error deleting AuditFinding ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        return $this->json([
            'success' => $deleted > 0,
            'deleted' => $deleted,
            'errors' => $errors,
            'message' => "$deleted audit findings deleted successfully",
        ]);
    }
}
