<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\AuditFinding;
use App\Entity\Tenant;
use App\Form\AuditFindingType;
use App\Repository\AuditFindingRepository;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * H-01: CRUD for structured Audit Findings (ISO 27001 Clause 10.1).
 */
#[IsGranted('ROLE_USER')]
#[Route('/audit-finding', name: 'app_audit_finding_')]
class AuditFindingController extends AbstractController
{
    public function __construct(
        private readonly AuditFindingRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $status = $request->query->get('status');
        $severity = $request->query->get('severity');

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

        $findings = $this->repository->findBy($criteria, ['createdAt' => 'DESC']);
        $overdue = $tenant instanceof Tenant ? $this->repository->findOverdue($tenant) : [];

        return $this->render('audit_finding/index.html.twig', [
            'findings' => $findings,
            'overdue_count' => count($overdue),
            'selected_status' => $status,
            'selected_severity' => $severity,
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

        $form = $this->createForm(AuditFindingType::class, $finding);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($finding);
            $this->entityManager->flush();

            $this->auditLogger->logCreate(
                'AuditFinding',
                $finding->getId(),
                ['title' => $finding->getTitle(), 'severity' => $finding->getSeverity()],
                'AuditFinding created'
            );

            $this->addFlash('success', 'audit_finding.flash.created');
            return $this->redirectToRoute('app_audit_finding_show', ['id' => $finding->getId()]);
        }

        return $this->render('audit_finding/new.html.twig', ['form' => $form]);
    }

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(AuditFinding $finding): Response
    {
        $this->denyIfWrongTenant($finding);

        return $this->render('audit_finding/show.html.twig', [
            'finding' => $finding,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Request $request, AuditFinding $finding): Response
    {
        $this->denyIfWrongTenant($finding);

        $form = $this->createForm(AuditFindingType::class, $finding);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

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

        return $this->render('audit_finding/edit.html.twig', [
            'finding' => $finding,
            'form' => $form,
        ]);
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
}
