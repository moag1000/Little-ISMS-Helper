<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresModule;
use App\Entity\ManagementDecision;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ManagementDecisionStatus;
use App\Enum\ManagementDecisionType;
use App\Form\ManagementDecisionType as ManagementDecisionFormType;
use App\Lifecycle\Exception\ReasonRequiredException;
use App\Lifecycle\InvalidTransitionException;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Repository\ManagementDecisionRepository;
use App\Service\ManagementDecision\ManagementDecisionService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use App\Util\ListPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Decisions of top management (ISO 27001 5.1, 6.1.2 a, 6.1.3 d/f, 9.3.3;
 * NIS-2 Art. 20): the CISO/ISB puts a proposal prefilled from the records
 * before top management, top management approves or rejects it. Decisions
 * taken elsewhere (risk acceptance at board level, completed management
 * review, automatic acceptance under approved criteria) appear here too.
 */
#[IsGranted('ROLE_AUDITOR')]
final class ManagementDecisionController extends AbstractController
{
    private const string WORKFLOW = 'management_decision_lifecycle';

    public function __construct(
        private readonly ManagementDecisionRepository $repository,
        private readonly ManagementDecisionService $decisions,
        private readonly LifecycleTransitionInterface $lifecycle,
        private readonly TenantContext $tenantContext,
        private readonly ModuleConfigurationService $modules,
    ) {
    }

    #[Route('/management-decisions', name: 'app_management_decision_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenant();
        $status = (string) $request->query->get('status', '');
        $type = ManagementDecisionType::tryFrom((string) $request->query->get('type', ''));

        $all = $this->repository->findByTenant($tenant);
        $rows = array_values(array_filter(
            $all,
            static fn (ManagementDecision $d): bool => ($status === '' || $d->getStatus() === $status)
                && ($type === null || $d->getType() === $type),
        ));

        return $this->render('management_decision/index.html.twig', [
            'page' => ListPage::fromRequest($rows, $request, static fn (ManagementDecision $d): array => [
                (string) $d->getTitle(),
                (string) $d->getProposal(),
            ]),
            'status' => $status,
            'type' => $type?->value,
            'statuses' => ManagementDecisionStatus::cases(),
            'types' => ManagementDecisionType::cases(),
            'pending_count' => count(array_filter($all, static fn (ManagementDecision $d): bool => $d->isPending())),
            'overdue_count' => count(array_filter($all, static fn (ManagementDecision $d): bool => $d->isOverdue())),
        ]);
    }

    /** Without `type` a picker; with `type` the proposal prefilled from the records. */
    #[Route('/management-decisions/new', name: 'app_management_decision_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(Request $request): Response
    {
        $tenant = $this->tenant();
        $proposable = ManagementDecisionType::proposable($this->modules->isModuleActive('nis2_dora'), $tenant->isDoraObligated());
        $type = ManagementDecisionType::tryFrom((string) $request->query->get('type', ''));
        if ($type === null || !in_array($type, $proposable, true)) {
            return $this->render('management_decision/new.html.twig', [
                'types' => $proposable,
                'current' => array_combine(
                    array_map(static fn (ManagementDecisionType $t): string => $t->value, $proposable),
                    array_map(fn (ManagementDecisionType $t): ?ManagementDecision => $this->decisions->currentApproval($tenant, $t), $proposable),
                ),
                'form' => null,
                'type' => null,
            ]);
        }

        $user = $this->getUser();
        $decision = $this->decisions->prepare($tenant, $type, $user instanceof User ? $user : null, $request->getLocale());
        $form = $this->createForm(ManagementDecisionFormType::class, $decision);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->decisions->submit($decision);
            $this->addFlash('success', 'management_decision.flash.submitted');

            return $this->redirectToRoute('app_management_decision_show', ['id' => $decision->getId()]);
        }

        return $this->render('management_decision/new.html.twig', [
            'types' => $proposable,
            'current' => [],
            'form' => $form,
            'type' => $type,
            'decision' => $decision,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /**
     * S12: put the risk of one outsourcing before top management (MaRisk AT 4.3).
     * The approval sets the supplier's board-level risk acceptance; the supplier
     * form shows it read-only.
     */
    #[Route('/management-decisions/new/supplier/{id}', name: 'app_management_decision_new_supplier', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[RequiresModule('suppliers')]
    public function newForSupplier(Request $request, Supplier $supplier): Response
    {
        $tenant = $this->tenant();
        if ($supplier->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException();
        }
        $user = $this->getUser();
        $decision = $this->decisions->prepareForSupplier($tenant, $supplier, $user instanceof User ? $user : null, $request->getLocale());
        $form = $this->createForm(ManagementDecisionFormType::class, $decision);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->decisions->submit($decision);
            $this->addFlash('success', 'management_decision.flash.submitted');

            return $this->redirectToRoute('app_management_decision_show', ['id' => $decision->getId()]);
        }

        return $this->render('management_decision/new.html.twig', [
            'types' => [],
            'current' => [],
            'form' => $form,
            'type' => ManagementDecisionType::SupplierRiskAcceptance,
            'decision' => $decision,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    #[Route('/management-decisions/{id}', name: 'app_management_decision_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(ManagementDecision $decision): Response
    {
        return $this->render('management_decision/show.html.twig', [
            'decision' => $decision,
            'superseded_by' => $decision->getStatus() === ManagementDecisionStatus::Superseded->value && $decision->getTenant() instanceof Tenant
                ? $this->decisions->currentApproval($decision->getTenant(), $decision->getType())
                : null,
        ]);
    }

    #[Route('/management-decisions/{id}/approve', name: 'app_management_decision_approve', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_TOP_MGMT')]
    #[IsCsrfTokenValid(new Expression('"md_approve_" ~ args["decision"].getId()'))]
    public function approve(Request $request, ManagementDecision $decision): Response
    {
        return $this->decide($request, $decision, 'approve', 'management_decision.flash.approved');
    }

    #[Route('/management-decisions/{id}/reject', name: 'app_management_decision_reject', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_TOP_MGMT')]
    #[IsCsrfTokenValid(new Expression('"md_reject_" ~ args["decision"].getId()'))]
    public function reject(Request $request, ManagementDecision $decision): Response
    {
        return $this->decide($request, $decision, 'reject', 'management_decision.flash.rejected');
    }

    #[Route('/management-decisions/{id}/withdraw', name: 'app_management_decision_withdraw', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid(new Expression('"md_withdraw_" ~ args["decision"].getId()'))]
    public function withdraw(Request $request, ManagementDecision $decision): Response
    {
        return $this->decide($request, $decision, 'withdraw', 'management_decision.flash.withdrawn');
    }

    private function decide(Request $request, ManagementDecision $decision, string $transition, string $flash): Response
    {
        if (!$this->isGranted('lifecycle.' . self::WORKFLOW . '.' . $transition, $decision)) {
            throw $this->createAccessDeniedException();
        }
        $user = $this->getUser();
        $comment = trim((string) $request->request->get('comment', ''));
        try {
            $this->lifecycle->transition(
                $decision,
                self::WORKFLOW,
                $transition,
                $user instanceof User ? $user : null,
                $comment !== '' ? $comment : null,
            );
            $this->addFlash('success', $flash);
        } catch (ReasonRequiredException) {
            $this->addFlash('warning', 'management_decision.flash.reason_required');
        } catch (InvalidTransitionException) {
            $this->addFlash('warning', 'management_decision.flash.already_decided');
        }

        return match ($request->request->get('_back')) {
            'board' => $this->redirectToRoute('app_dashboard_board'),
            'my_day' => $this->redirectToRoute('app_my_day'),
            default => $this->redirectToRoute('app_management_decision_show', ['id' => $decision->getId()]),
        };
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createAccessDeniedException();
        }

        return $tenant;
    }
}
