<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\LocalizedFlashTrait;
use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\AuditProgram;
use App\Form\AuditProgramType;
use App\Repository\AuditProgramRepository;
use App\Service\AuditLogger;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * AuditProgramController — ISO 19011 §5.4 Audit Programme CRUD.
 *
 * RBAC: ROLE_AUDITOR required for read-actions.
 * Write actions (new/edit/archive) require ROLE_MANAGER.
 * Module gate: `audits`.
 */
#[IsGranted('ROLE_AUDITOR')]
#[Route('/audit-programs', name: 'app_audit_program_')]
class AuditProgramController extends AbstractController
{
    use LocalizedFlashTrait;
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly AuditProgramRepository $auditProgramRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly ModuleConfigurationService $moduleService,
    ) {}

    protected function getFlashDomain(): string
    {
        return 'audit_program';
    }

    protected function getTranslator(): TranslatorInterface
    {
        return $this->translator;
    }

    // ── Index ──────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('audits')) {
            return $redirect;
        }

        $tenant   = $this->tenantContext->getCurrentTenant();
        $programs = $tenant !== null
            ? $this->auditProgramRepository->findAllByTenant($tenant)
            : [];

        return $this->render('audit_program/index.html.twig', [
            'programs' => $programs,
        ]);
    }

    // ── Show ───────────────────────────────────────────────────────────────────

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(AuditProgram $auditProgram): Response
    {
        if ($redirect = $this->checkModuleActive('audits')) {
            return $redirect;
        }

        return $this->render('audit_program/show.html.twig', [
            'program' => $auditProgram,
        ]);
    }

    // ── New ────────────────────────────────────────────────────────────────────

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('audits')) {
            return $redirect;
        }

        $program = new AuditProgram();
        $program->setTenant($this->tenantContext->getCurrentTenant());
        $program->setCreatedBy($this->getUser());

        $form = $this->createForm(AuditProgramType::class, $program);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($program);
            $this->entityManager->flush();

            $this->auditLogger->logCreate(
                'AuditProgram',
                $program->getId(),
                ['name' => $program->getName()],
            );

            $this->flashSuccess('audit_program.success.created');

            return $this->redirectToRoute('app_audit_program_show', [
                '_locale' => $request->getLocale(),
                'id'      => $program->getId(),
            ]);
        }

        return $this->render('audit_program/new.html.twig', [
            'form'    => $form,
            'program' => $program,
        ]);
    }

    // ── Edit ───────────────────────────────────────────────────────────────────

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    public function edit(Request $request, AuditProgram $auditProgram): Response
    {
        if ($redirect = $this->checkModuleActive('audits')) {
            return $redirect;
        }

        $form = $this->createForm(AuditProgramType::class, $auditProgram);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $auditProgram->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->auditLogger->logUpdate(
                'AuditProgram',
                $auditProgram->getId(),
                [],
                ['name' => $auditProgram->getName()],
            );

            $this->flashSuccess('audit_program.success.updated');

            return $this->redirectToRoute('app_audit_program_show', [
                '_locale' => $request->getLocale(),
                'id'      => $auditProgram->getId(),
            ]);
        }

        return $this->render('audit_program/edit.html.twig', [
            'form'    => $form,
            'program' => $auditProgram,
        ]);
    }

    // ── Archive ────────────────────────────────────────────────────────────────

    #[Route('/{id}/archive', name: 'archive', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid('archive-audit-program-{id}')]
    public function archive(Request $request, AuditProgram $auditProgram): Response
    {
        if ($redirect = $this->checkModuleActive('audits')) {
            return $redirect;
        }

        $auditProgram->setStatus('archived');
        $auditProgram->setArchivedAt(new DateTimeImmutable());
        $auditProgram->setUpdatedAt(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->auditLogger->logUpdate(
            'AuditProgram',
            $auditProgram->getId(),
            ['status' => 'active'],
            ['status' => 'archived'],
        );

        $this->flashSuccess('audit_program.success.archived');

        return $this->redirectToRoute('app_audit_program_index', [
            '_locale' => $request->getLocale(),
        ]);
    }

    // ── Delete ─────────────────────────────────────────────────────────────────

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    #[IsCsrfTokenValid('delete-audit-program-{id}')]
    public function delete(Request $request, AuditProgram $auditProgram): Response
    {
        if ($redirect = $this->checkModuleActive('audits')) {
            return $redirect;
        }

        $name = $auditProgram->getName();

        $this->entityManager->remove($auditProgram);
        $this->entityManager->flush();

        $this->auditLogger->logDelete('AuditProgram', null, ['name' => $name]);
        $this->flashSuccess('audit_program.success.deleted');

        return $this->redirectToRoute('app_audit_program_index', ['_locale' => $request->getLocale()]);
    }
}
