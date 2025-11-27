<?php

namespace App\Controller;

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

#[Route('/business-continuity-plan')]
class BusinessContinuityPlanController extends AbstractController
{
    public function __construct(
        private BusinessContinuityPlanRepository $bcPlanRepository,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private TenantContext $tenantContext
    ) {}

    #[Route('/', name: 'app_bc_plan_index')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $bcPlans = $this->bcPlanRepository->findAll();
        $overdueTests = $this->bcPlanRepository->findOverdueTests();
        $overdueReviews = $this->bcPlanRepository->findOverdueReviews();
        $activePlans = $this->bcPlanRepository->findActivePlans();

        return $this->render('business_continuity_plan/index.html.twig', [
            'bc_plans' => $bcPlans,
            'overdue_tests' => $overdueTests,
            'overdue_reviews' => $overdueReviews,
            'active_plans' => $activePlans,
        ]);
    }

    #[Route('/new', name: 'app_bc_plan_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $bcPlan = new BusinessContinuityPlan();
        $bcPlan->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(BusinessContinuityPlanType::class, $bcPlan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($bcPlan);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('business_continuity_plan.success.created'));
            return $this->redirectToRoute('app_bc_plan_show', ['id' => $bcPlan->getId()]);
        }

        return $this->render('business_continuity_plan/new.html.twig', [
            'bc_plan' => $bcPlan,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_bc_plan_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(BusinessContinuityPlan $bcPlan): Response
    {
        return $this->render('business_continuity_plan/show.html.twig', [
            'bc_plan' => $bcPlan,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_bc_plan_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, BusinessContinuityPlan $bcPlan): Response
    {
        $form = $this->createForm(BusinessContinuityPlanType::class, $bcPlan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $bcPlan->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('business_continuity_plan.success.updated'));
            return $this->redirectToRoute('app_bc_plan_show', ['id' => $bcPlan->getId()]);
        }

        return $this->render('business_continuity_plan/edit.html.twig', [
            'bc_plan' => $bcPlan,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_bc_plan_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, BusinessContinuityPlan $bcPlan): Response
    {
        if ($this->isCsrfTokenValid('delete'.$bcPlan->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($bcPlan);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('business_continuity_plan.success.deleted'));
        }

        return $this->redirectToRoute('app_bc_plan_index');
    }
}
