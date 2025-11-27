<?php

namespace App\Controller;

use DateTimeImmutable;
use App\Entity\BusinessContinuityPlan;
use App\Form\BusinessContinuityPlanType;
use App\Repository\BusinessContinuityPlanRepository;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class BusinessContinuityPlanController extends AbstractController
{
    public function __construct(
        private readonly BusinessContinuityPlanRepository $businessContinuityPlanRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext
    ) {}
    #[Route('/business-continuity-plan/', name: 'app_bc_plan_index')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $bcPlans = $this->businessContinuityPlanRepository->findAll();
        $overdueTests = $this->businessContinuityPlanRepository->findOverdueTests();
        $overdueReviews = $this->businessContinuityPlanRepository->findOverdueReviews();
        $activePlans = $this->businessContinuityPlanRepository->findActivePlans();

        return $this->render('business_continuity_plan/index.html.twig', [
            'bc_plans' => $bcPlans,
            'overdue_tests' => $overdueTests,
            'overdue_reviews' => $overdueReviews,
            'active_plans' => $activePlans,
        ]);
    }
    #[Route('/business-continuity-plan/new', name: 'app_bc_plan_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $businessContinuityPlan = new BusinessContinuityPlan();
        $businessContinuityPlan->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(BusinessContinuityPlanType::class, $businessContinuityPlan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($businessContinuityPlan);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('business_continuity_plan.success.created'));
            return $this->redirectToRoute('app_bc_plan_show', ['id' => $businessContinuityPlan->getId()]);
        }

        return $this->render('business_continuity_plan/new.html.twig', [
            'bc_plan' => $businessContinuityPlan,
            'form' => $form,
        ]);
    }
    #[Route('/business-continuity-plan/{id}', name: 'app_bc_plan_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(BusinessContinuityPlan $businessContinuityPlan): Response
    {
        return $this->render('business_continuity_plan/show.html.twig', [
            'bc_plan' => $businessContinuityPlan,
        ]);
    }
    #[Route('/business-continuity-plan/{id}/edit', name: 'app_bc_plan_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, BusinessContinuityPlan $businessContinuityPlan): Response
    {
        $form = $this->createForm(BusinessContinuityPlanType::class, $businessContinuityPlan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $businessContinuityPlan->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('business_continuity_plan.success.updated'));
            return $this->redirectToRoute('app_bc_plan_show', ['id' => $businessContinuityPlan->getId()]);
        }

        return $this->render('business_continuity_plan/edit.html.twig', [
            'bc_plan' => $businessContinuityPlan,
            'form' => $form,
        ]);
    }
    #[Route('/business-continuity-plan/{id}/delete', name: 'app_bc_plan_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, BusinessContinuityPlan $businessContinuityPlan): Response
    {
        if ($this->isCsrfTokenValid('delete'.$businessContinuityPlan->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($businessContinuityPlan);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('business_continuity_plan.success.deleted'));
        }

        return $this->redirectToRoute('app_bc_plan_index');
    }
}
