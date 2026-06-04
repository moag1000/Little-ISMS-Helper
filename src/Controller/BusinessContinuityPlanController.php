<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Controller\Trait\BulkActionTrait;
use App\Entity\BusinessContinuityPlan;
use App\Form\BusinessContinuityPlanType;
use App\Repository\BusinessContinuityPlanRepository;
use App\Service\AuditLogger;
use App\Service\BusinessContinuityPlanService;
use App\Service\Clone\BusinessContinuityPlanCloner;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class BusinessContinuityPlanController extends AbstractController
{
    use ModuleGatedControllerTrait;
    use BulkActionTrait;

    public function __construct(
        private readonly BusinessContinuityPlanRepository $businessContinuityPlanRepository,
        private readonly BusinessContinuityPlanService $businessContinuityPlanService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $moduleService,
        private readonly Security $security,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?BusinessContinuityPlanCloner $bcPlanCloner = null,
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
            $this->businessContinuityPlanService->create($businessContinuityPlan);

            $this->addFlash('success', $this->translator->trans('business_continuity_plan.success.created', [], 'messages'));
            return $this->redirectToRoute('app_bc_plan_show', ['id' => $businessContinuityPlan->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('business_continuity_plan/new.html.twig', [
            'bc_plan' => $businessContinuityPlan,
            'form' => $form,
        ], new Response(status: $status));
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
    /**
     * Clone a BC-Plan (C4-C1 — Klon-Funktionen). Open to ROLE_USER. Keeps
     * operational template (RTO/RPO, procedures, response team, M2M
     * crisis teams + critical suppliers/assets), resets test/review state.
     */
    #[Route('/business-continuity-plan/{id}/clone', name: 'app_bc_plan_clone', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function clone(Request $request, BusinessContinuityPlan $businessContinuityPlan): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;
        if (!$this->isCsrfTokenValid('clone_bc_plan_' . $businessContinuityPlan->getId(), (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if ($this->bcPlanCloner === null) {
            throw $this->createNotFoundException('BC-Plan clone service is not available.');
        }

        $clone = $this->bcPlanCloner->clone(
            $businessContinuityPlan,
            null,
            trim((string) $request->request->get('title_override', '')) ?: null,
        );
        $this->businessContinuityPlanService->persistClone($clone, $businessContinuityPlan);

        $this->addFlash('success', $this->translator->trans('bc_plans.clone.success', [], 'bc_plans'));
        return $this->redirectToRoute('app_bc_plan_edit', ['id' => $clone->getId()]);
    }

    #[Route('/business-continuity-plan/{id}/edit', name: 'app_bc_plan_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, BusinessContinuityPlan $businessContinuityPlan): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        $form = $this->createForm(BusinessContinuityPlanType::class, $businessContinuityPlan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->businessContinuityPlanService->update($businessContinuityPlan);

            $this->addFlash('success', $this->translator->trans('business_continuity_plan.success.updated', [], 'messages'));
            return $this->redirectToRoute('app_bc_plan_show', ['id' => $businessContinuityPlan->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('business_continuity_plan/edit.html.twig', [
            'bc_plan' => $businessContinuityPlan,
            'form' => $form,
        ], new Response(status: $status));
    }
    #[Route('/business-continuity-plan/{id}/delete', name: 'app_bc_plan_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, BusinessContinuityPlan $businessContinuityPlan): Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) return $redirect;

        if ($this->isCsrfTokenValid('delete'.$businessContinuityPlan->getId(), $request->request->get('_token'))) {
            $this->businessContinuityPlanService->delete($businessContinuityPlan);

            $this->addFlash('success', $this->translator->trans('business_continuity_plan.success.deleted', [], 'messages'));
        }

        return $this->redirectToRoute('app_bc_plan_index');
    }

    /**
     * Dependency-check endpoint for the Aurora bulk-delete-confirmation modal.
     * Warns if a BCPlan is referenced by BCExercises before deletion.
     */
    #[Route('/business-continuity-plan/bulk-delete-check', name: 'app_bc_plan_bulk_delete_check', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDeleteCheck(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $ids = array_filter((array) ($data['ids'] ?? []), 'is_int');
        if ($ids === []) {
            return new JsonResponse(['dependencies' => [], 'checked_count' => 0]);
        }

        $tenant = $this->security->getUser()?->getTenant();
        $plans = $this->businessContinuityPlanRepository->findBy(['id' => $ids, 'tenant' => $tenant]);

        $em = $this->entityManager;
        return $this->checkBulkDependencies($plans, 'getName', [
            fn (\App\Entity\BusinessContinuityPlan $plan): ?array => ($c = (int) $em->createQuery(
                'SELECT COUNT(e.id) FROM App\Entity\BCExercise e JOIN e.testedPlans p WHERE p.id = :id'
            )->setParameter('id', $plan->getId())->getSingleScalarResult()) > 0
                ? ['message' => sprintf('%d Übung(en) verknüpft', $c), 'icon' => 'clipboard-check']
                : null,
        ]);
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
        $result = $this->businessContinuityPlanService->bulkDelete($ids, $tenant?->getId());
        $deleted = $result['deleted'];
        $errors  = $result['errors'];

        return $this->json([
            'success' => $deleted > 0,
            'deleted' => $deleted,
            'errors' => $errors,
            'message' => "$deleted BC plans deleted successfully",
        ]);
    }

    /**
     * Bulk CSV export of selected BC plans.
     * Module-gated: bcm. ISO 27001 Cl. 7.5.3 — audit-logged via BulkActionTrait.
     */
    #[Route('/business-continuity-plan/bulk-export', name: 'app_bc_plan_bulk_export', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function bulkExport(Request $request): StreamedResponse|Response
    {
        if ($redirect = $this->checkModuleActive('bcm')) {
            return $redirect;
        }

        $data = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('bulk_action', (string) ($data['_token'] ?? ''))) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }
        $ids  = $data['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $tenant = $this->tenantContext->getCurrentTenant();

        $plans = [];
        foreach ($ids as $rawId) {
            $plan = $this->businessContinuityPlanRepository->find((int) $rawId);
            if ($plan === null) {
                continue;
            }
            if ($tenant !== null && $plan->getTenant() !== $tenant) {
                continue;
            }
            $plans[] = $plan;
        }

        if ($plans === []) {
            return $this->json(['error' => 'No exportable BC plans'], 404);
        }

        $headers = ['ID', 'Name', 'Status', 'Description'];

        return $this->streamCsvExport(
            $plans,
            $headers,
            static function (BusinessContinuityPlan $p): array {
                return [
                    (string) $p->getId(),
                    (string) $p->getName(),
                    (string) $p->getStatus(),
                    (string) $p->getDescription(),
                ];
            },
            'bc-plans-export',
            'BusinessContinuityPlan',
            $this->auditLogger,
        );
    }
}
