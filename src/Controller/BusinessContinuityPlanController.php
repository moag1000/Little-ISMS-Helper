<?php

declare(strict_types=1);

namespace App\Controller;

use DateTimeImmutable;
use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\BusinessContinuityPlan;
use App\Form\BusinessContinuityPlanType;
use App\Repository\BusinessContinuityPlanRepository;
use App\Service\ModuleConfigurationService;
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
use Symfony\Contracts\Translation\TranslatorInterface;

class BusinessContinuityPlanController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly BusinessContinuityPlanRepository $businessContinuityPlanRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleService,
        private readonly Security $security,
    ) {}
    #[Route('/business-continuity-plan', name: 'app_bc_plan_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        $tenant = $this->tenantContext->getCurrentTenant();
        $bcPlans = $tenant ? $this->businessContinuityPlanRepository->findBy(['tenant' => $tenant]) : [];
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
    #[Route('/business-continuity-plan/new', name: 'app_bc_plan_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        $businessContinuityPlan = new BusinessContinuityPlan();
        $businessContinuityPlan->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(BusinessContinuityPlanType::class, $businessContinuityPlan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($businessContinuityPlan);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('business_continuity_plan.success.created', [], 'messages'));
            return $this->redirectToRoute('app_bc_plan_show', ['id' => $businessContinuityPlan->getId()]);
        }

        return $this->render('business_continuity_plan/new.html.twig', [
            'bc_plan' => $businessContinuityPlan,
            'form' => $form,
        ]);
    }
    #[Route('/business-continuity-plan/{id}', name: 'app_bc_plan_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(BusinessContinuityPlan $businessContinuityPlan): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        return $this->render('business_continuity_plan/show.html.twig', [
            'bc_plan' => $businessContinuityPlan,
        ]);
    }
    #[Route('/business-continuity-plan/{id}/edit', name: 'app_bc_plan_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, BusinessContinuityPlan $businessContinuityPlan): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        $form = $this->createForm(BusinessContinuityPlanType::class, $businessContinuityPlan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $businessContinuityPlan->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('business_continuity_plan.success.updated', [], 'messages'));
            return $this->redirectToRoute('app_bc_plan_show', ['id' => $businessContinuityPlan->getId()]);
        }

        return $this->render('business_continuity_plan/edit.html.twig', [
            'bc_plan' => $businessContinuityPlan,
            'form' => $form,
        ]);
    }
    #[Route('/business-continuity-plan/{id}/delete', name: 'app_bc_plan_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, BusinessContinuityPlan $businessContinuityPlan): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        if ($this->isCsrfTokenValid('delete'.$businessContinuityPlan->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($businessContinuityPlan);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('business_continuity_plan.success.deleted')); // @todo H-06 flash-domain
        }

        return $this->redirectToRoute('app_bc_plan_index');
    }

    #[Route('/business-continuity-plan/bulk-delete', name: 'app_bc_plan_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDelete(Request $request): JsonResponse
    {
        if ($this->checkModuleActive('bcm') instanceof Response) {
            return $this->json(['error' => 'BCM module not active'], 403);
        }

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
                $plan = $this->businessContinuityPlanRepository->find($id);
                if (!$plan) {
                    $errors[] = "BC Plan ID $id not found";
                    continue;
                }
                if ($tenant && $plan->getTenant() !== $tenant) {
                    $errors[] = "BC Plan ID $id does not belong to your organization";
                    continue;
                }
                $this->entityManager->remove($plan);
                $deleted++;
            } catch (Exception $e) {
                $errors[] = "Error deleting BC Plan ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        return $this->json([
            'success' => $deleted > 0,
            'deleted' => $deleted,
            'errors' => $errors,
            'message' => "$deleted BC plans deleted successfully",
        ]);
    }
}
