<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\InPageFormTrait;
use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\PrototypeProtectionAssessment;
use App\Util\ListPage;
use App\Form\PrototypeProtectionAssessmentType;
use App\Repository\PrototypeProtectionAssessmentRepository;
use App\Security\TenantOwnership;
use App\Security\Voter\RecordResponsibleVoter;
use App\Service\ModuleConfigurationService;
use App\Service\PdfExportService;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * TISAX / VDA-ISA 6 Kapitel 8 — Prototype-Protection Assessment CRUD.
 *
 * Supports the full assessment lifecycle:
 *   draft → in_review → approved/rejected → expired
 *
 * ROLE_MANAGER, except that the assessor (RecordResponsibleVoter) opens, edits
 * and prints the assessment they are reminded to reassess.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/prototype-protection', name: 'app_prototype_protection_')]
class PrototypeProtectionAssessmentController extends AbstractController
{
    use ModuleGatedControllerTrait;
    use InPageFormTrait;

    public function __construct(
        private readonly PrototypeProtectionAssessmentRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
        private readonly ModuleConfigurationService $moduleService,
        private readonly ?PdfExportService $pdfExportService = null,
    ) {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    #[IsGranted('ROLE_MANAGER')]
    public function index(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('tisax')) return $redirect;

        $tenant = $this->tenantContext->getCurrentTenant();
        $assessments = $tenant
            ? $this->repository->findForTenant($tenant)
            : [];
        $expiring = $tenant
            ? $this->repository->findExpiringSoon($tenant)
            : [];

        return $this->render('prototype_protection/index.html.twig', [
            'page' => ListPage::fromRequest(
                $assessments,
                $request,
                static fn(PrototypeProtectionAssessment $a): array => [$a->getTitle(), $a->getTisaxLevel()],
            ),
            'not_met_count' => count(array_filter($assessments, static fn(PrototypeProtectionAssessment $a): bool => $a->getOverallResult() === 'not_met')),
            'in_review_count' => count(array_filter($assessments, static fn(PrototypeProtectionAssessment $a): bool => $a->getStatus() === 'in_review')),
            'expiring' => $expiring,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('tisax')) return $redirect;

        $assessment = new PrototypeProtectionAssessment();
        $assessment->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(PrototypeProtectionAssessmentType::class, $assessment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $assessment->setOverallResult($assessment->computeOverallResult());
            $this->entityManager->persist($assessment);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('prototype_protection.flash.created', [], 'prototype_protection'));

            if ($this->isTurboFrameRequest($request)) {
                return $this->prototypeProtectionStreamSave($assessment, isNew: true);
            }
            return $this->redirectToRoute('app_prototype_protection_show', ['id' => $assessment->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('prototype_protection/_form_modal.html.twig', [
                'assessment' => $assessment,
                'form' => $form,
            ], new Response(status: $status));
        }

        return $this->render('prototype_protection/new.html.twig', [
            'assessment' => $assessment,
            'form' => $form,
        ], new Response(status: $status));
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(new Expression(RecordResponsibleVoter::MANAGER_OR_RESPONSIBLE), subject: 'assessment')]
    public function show(Request $request, PrototypeProtectionAssessment $assessment): Response
    {
        if ($redirect = $this->checkModuleActive('tisax')) return $redirect;

        $this->assertAccess($assessment);

        // In-frame request → condensed detail modal; direct URL → full page.
        if ($this->isTurboFrameRequest($request)) {
            return $this->render('prototype_protection/_detail_modal.html.twig', [
                'assessment' => $assessment,
            ]);
        }

        return $this->render('prototype_protection/show.html.twig', [
            'assessment' => $assessment,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted(new Expression(RecordResponsibleVoter::MANAGER_OR_RESPONSIBLE), subject: 'assessment')]
    public function edit(Request $request, PrototypeProtectionAssessment $assessment): Response
    {
        if ($redirect = $this->checkModuleActive('tisax')) return $redirect;

        $this->assertAccess($assessment);

        $form = $this->createForm(PrototypeProtectionAssessmentType::class, $assessment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $assessment->setOverallResult($assessment->computeOverallResult());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('prototype_protection.flash.updated', [], 'prototype_protection'));

            if ($this->isTurboFrameRequest($request)) {
                return $this->prototypeProtectionStreamSave($assessment, isNew: false);
            }
            return $this->redirectToRoute('app_prototype_protection_show', ['id' => $assessment->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('prototype_protection/_form_modal.html.twig', [
                'assessment' => $assessment,
                'form' => $form,
            ], new Response(status: $status));
        }

        return $this->render('prototype_protection/edit.html.twig', [
            'assessment' => $assessment,
            'form' => $form,
        ], new Response(status: $status));
    }

    #[Route('/{id}/pdf', name: 'pdf', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted(new Expression(RecordResponsibleVoter::MANAGER_OR_RESPONSIBLE), subject: 'assessment')]
    public function pdf(PrototypeProtectionAssessment $assessment): Response
    {
        if ($redirect = $this->checkModuleActive('tisax')) return $redirect;

        $this->assertAccess($assessment);
        if ($this->pdfExportService === null) {
            throw $this->createNotFoundException('PDF export service is not available.');
        }

        $generatedAt = new DateTimeImmutable();
        $pdf = $this->pdfExportService->generatePdf(
            'prototype_protection/pdf/report.html.twig',
            [
                'assessment' => $assessment,
                'generated_at' => $generatedAt,
            ],
            [
                'classification' => $assessment->getTenant()?->getName(),
            ]
        );

        $dateSlug = $assessment->getAssessmentDate()?->format('Y-m-d') ?? $generatedAt->format('Y-m-d');
        $titleSlug = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $assessment->getTitle()) ?? 'prototype-protection';
        $filename = sprintf('prototype-protection_%s_%s.pdf', $dateSlug, trim($titleSlug, '-') ?: 'assessment');

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, PrototypeProtectionAssessment $assessment): Response
    {
        if ($redirect = $this->checkModuleActive('tisax')) return $redirect;

        $this->assertAccess($assessment);

        if ($this->isCsrfTokenValid('delete' . $assessment->getId(), (string) $request->request->get('_token'))) {
            $this->entityManager->remove($assessment);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('prototype_protection.flash.deleted', [], 'prototype_protection'));
        }

        return $this->redirectToRoute('app_prototype_protection_index');
    }

    /** Turbo Stream after a successful in-modal PrototypeProtectionAssessment save (row replace/append). */
    private function prototypeProtectionStreamSave(PrototypeProtectionAssessment $assessment, bool $isNew): Response
    {
        return $this->render('prototype_protection/_stream_save.html.twig', [
            'assessment' => $assessment,
            'is_new' => $isNew,
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }

    private function assertAccess(PrototypeProtectionAssessment $assessment): void
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $isSuperAdmin = $tenant === null && $this->isGranted('ROLE_SUPER_ADMIN');
        if (!TenantOwnership::isInScope($assessment, $tenant, $isSuperAdmin)) {
            throw $this->createAccessDeniedException('Cross-tenant access denied');
        }
    }
}
