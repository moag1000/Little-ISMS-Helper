<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\PrototypeProtectionAssessment;
use App\Form\PrototypeProtectionAssessmentType;
use App\Repository\PrototypeProtectionAssessmentRepository;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
 */
#[IsGranted('ROLE_MANAGER')]
#[Route('/prototype-protection', name: 'app_prototype_protection_')]
class PrototypeProtectionAssessmentController extends AbstractController
{
    public function __construct(
        private readonly PrototypeProtectionAssessmentRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('/', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $assessments = $tenant
            ? $this->repository->findForTenant($tenant)
            : [];
        $expiring = $tenant
            ? $this->repository->findExpiringSoon($tenant)
            : [];

        return $this->render('prototype_protection/index.html.twig', [
            'assessments' => $assessments,
            'expiring' => $expiring,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $assessment = new PrototypeProtectionAssessment();
        $assessment->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(PrototypeProtectionAssessmentType::class, $assessment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $assessment->setOverallResult($assessment->computeOverallResult());
            $this->entityManager->persist($assessment);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('prototype_protection.flash.created', [], 'prototype_protection'));
            return $this->redirectToRoute('app_prototype_protection_show', ['id' => $assessment->getId()]);
        }

        return $this->render('prototype_protection/new.html.twig', [
            'assessment' => $assessment,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(PrototypeProtectionAssessment $assessment): Response
    {
        $this->assertAccess($assessment);
        return $this->render('prototype_protection/show.html.twig', [
            'assessment' => $assessment,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, PrototypeProtectionAssessment $assessment): Response
    {
        $this->assertAccess($assessment);

        $form = $this->createForm(PrototypeProtectionAssessmentType::class, $assessment);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $assessment->setOverallResult($assessment->computeOverallResult());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('prototype_protection.flash.updated', [], 'prototype_protection'));
            return $this->redirectToRoute('app_prototype_protection_show', ['id' => $assessment->getId()]);
        }

        return $this->render('prototype_protection/edit.html.twig', [
            'assessment' => $assessment,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, PrototypeProtectionAssessment $assessment): Response
    {
        $this->assertAccess($assessment);

        if ($this->isCsrfTokenValid('delete' . $assessment->getId(), (string) $request->request->get('_token'))) {
            $this->entityManager->remove($assessment);
            $this->entityManager->flush();
            $this->addFlash('success', $this->translator->trans('prototype_protection.flash.deleted', [], 'prototype_protection'));
        }

        return $this->redirectToRoute('app_prototype_protection_index');
    }

    private function assertAccess(PrototypeProtectionAssessment $assessment): void
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant !== null && $assessment->getTenant() !== null && $assessment->getTenant()->getId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException('Cross-tenant access denied');
        }
    }
}
