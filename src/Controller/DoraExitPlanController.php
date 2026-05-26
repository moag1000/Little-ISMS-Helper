<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\DoraExitPlan;
use App\Form\DoraExitPlanType;
use App\Repository\DoraExitPlanRepository;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * DORA Art. 28 RT_06 — exit-plan CRUD per critical Supplier.
 *
 * Module-gated behind nis2_dora — surfaces only when the tenant has
 * activated the DORA module. One plan per supplier (DB-unique).
 */
#[IsGranted('ROLE_USER')]
#[Route('/dora/exit-plan', name: 'app_dora_exit_plan_')]
class DoraExitPlanController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly DoraExitPlanRepository $exitPlanRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleService,
    ) {}

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $user = $this->security->getUser();
        $tenant = $user instanceof UserInterface ? $user->getTenant() : null;

        $plans = $tenant !== null
            ? $this->exitPlanRepository->findByTenant($tenant)
            : [];

        $overdue = array_filter($plans, fn(DoraExitPlan $p): bool => $p->isRehearsalOverdue());

        return $this->render('dora_exit_plan/index.html.twig', [
            'plans' => $plans,
            'overdue_count' => count($overdue),
            'total_count' => count($plans),
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $plan = new DoraExitPlan();

        $user = $this->security->getUser();
        if ($user instanceof UserInterface && $user->getTenant() !== null) {
            $plan->setTenant($user->getTenant());
        }

        $form = $this->createForm(DoraExitPlanType::class, $plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Enforce uniqueness at form-layer too (DB-constraint is the
            // last line of defence). One plan per Supplier.
            if ($plan->getSupplier() !== null
                && $this->exitPlanRepository->findOneBySupplier($plan->getSupplier()) !== null) {
                $this->addFlash('warning', $this->translator->trans(
                    'dora_exit_plan.flash.duplicate_supplier',
                    [],
                    'dora_exit_plan',
                ));

                return $this->render('dora_exit_plan/new.html.twig', [
                    'plan' => $plan,
                    'form' => $form,
                ]);
            }

            $this->entityManager->persist($plan);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans(
                'dora_exit_plan.flash.created',
                [],
                'dora_exit_plan',
            ));

            return $this->redirectToRoute('app_dora_exit_plan_show', ['id' => $plan->getId()]);
        }

        return $this->render('dora_exit_plan/new.html.twig', [
            'plan' => $plan,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(DoraExitPlan $plan): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        return $this->render('dora_exit_plan/show.html.twig', [
            'plan' => $plan,
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function edit(Request $request, DoraExitPlan $plan): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $form = $this->createForm(DoraExitPlanType::class, $plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans(
                'dora_exit_plan.flash.updated',
                [],
                'dora_exit_plan',
            ));

            return $this->redirectToRoute('app_dora_exit_plan_show', ['id' => $plan->getId()]);
        }

        return $this->render('dora_exit_plan/edit.html.twig', [
            'plan' => $plan,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function delete(Request $request, DoraExitPlan $plan): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        if ($this->isCsrfTokenValid('delete' . $plan->getId(), (string) $request->request->get('_token'))) {
            $this->entityManager->remove($plan);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans(
                'dora_exit_plan.flash.deleted',
                [],
                'dora_exit_plan',
            ));
        }

        return $this->redirectToRoute('app_dora_exit_plan_index');
    }
}
