<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\ActionItem;
use App\Form\ActionItemType;
use App\Repository\ActionItemRepository;
use App\Service\Planning\ActionItemStatusService;
use App\Service\Planning\InvalidActionItemTransitionException;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class ActionItemController extends AbstractController
{
    public function __construct(
        private readonly ActionItemRepository $actionItemRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ActionItemStatusService $statusService,
    ) {
    }

    #[Route('/planning/measures', name: 'app_planning_action_item_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        $user   = $this->security->getUser();
        $tenant = $user?->getTenant();

        $filter = $request->query->getString('filter', 'all');
        if (!in_array($filter, ['all', 'open', 'due', 'recurring'], true)) {
            $filter = 'all';
        }

        if ($tenant === null) {
            $items = [];
        } else {
            $items = match ($filter) {
                'open', 'due' => $this->actionItemRepository->findOpenByTenant($tenant),
                default       => $this->actionItemRepository->findByTenant($tenant),
            };

            if ($filter === 'due') {
                $cutoff = new DateTimeImmutable('+14 days');
                $items  = array_filter(
                    $items,
                    static fn (ActionItem $i): bool => $i->getDueDate() !== null && $i->getDueDate() <= $cutoff,
                );
            } elseif ($filter === 'recurring') {
                $items = array_filter(
                    $items,
                    static fn (ActionItem $i): bool => $i->getRecurrenceMonths() !== null,
                );
            }
        }

        /** @var array<int, list<string>> $transitions */
        $transitions = [];
        foreach ($items as $item) {
            if ($item->getId() !== null) {
                $transitions[$item->getId()] = $this->statusService->allowedTargets($item);
            }
        }

        return $this->render('planning/action_item/index.html.twig', [
            'items'       => array_values($items),
            'filter'      => $filter,
            'transitions' => $transitions,
        ]);
    }

    #[Route('/planning/measures/new', name: 'app_planning_action_item_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $item   = new ActionItem();
        $tenant = $this->security->getUser()?->getTenant();
        if ($tenant !== null) {
            $item->setTenant($tenant);
        }
        $item->setOrigin(ActionItem::ORIGIN_INTERNAL);

        $form = $this->createForm(ActionItemType::class, $item);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($item);
            $this->entityManager->flush();

            $this->addFlash(
                'success',
                $this->translator->trans('planning.action_item.success.created', [], 'planning'),
            );

            return $this->redirectToRoute('app_planning_action_item_index');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('planning/action_item/new.html.twig', [
            'item' => $item,
            'form' => $form,
        ], new Response(status: $status));
    }

    #[Route('/planning/measures/{id}/edit', name: 'app_planning_action_item_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('edit', 'actionItem')]
    public function edit(Request $request, ActionItem $actionItem): Response
    {
        $form = $this->createForm(ActionItemType::class, $actionItem);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $actionItem->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash(
                'success',
                $this->translator->trans('planning.action_item.success.updated', [], 'planning'),
            );

            return $this->redirectToRoute('app_planning_action_item_index');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('planning/action_item/edit.html.twig', [
            'item' => $actionItem,
            'form' => $form,
        ], new Response(status: $status));
    }

    #[Route('/planning/measures/{id}/delete', name: 'app_planning_action_item_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('delete', 'actionItem')]
    public function delete(Request $request, ActionItem $actionItem): Response
    {
        if ($this->isCsrfTokenValid('delete'.$actionItem->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($actionItem);
            $this->entityManager->flush();

            $this->addFlash(
                'success',
                $this->translator->trans('planning.action_item.success.deleted', [], 'planning'),
            );
        }

        return $this->redirectToRoute('app_planning_action_item_index');
    }

    #[Route('/planning/measures/{id}/transition', name: 'app_planning_action_item_transition', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('edit', 'actionItem')]
    public function transition(Request $request, ActionItem $actionItem): Response
    {
        if (!$this->isCsrfTokenValid('transition'.$actionItem->getId(), $request->request->get('_token'))) {
            $this->addFlash(
                'error',
                $this->translator->trans('planning.action_item.error.invalid_transition', [], 'planning'),
            );

            return $this->redirectToRoute('app_planning_action_item_index');
        }

        $to     = (string) $request->request->get('to', '');
        $reason = $request->request->get('reason');
        $reason = is_string($reason) && $reason !== '' ? $reason : null;

        /** @var \App\Entity\User $user */
        $user = $this->security->getUser();

        try {
            $this->statusService->transition($actionItem, $to, $user, $reason);
            $this->addFlash(
                'success',
                $this->translator->trans('planning.action_item.success.transitioned', [], 'planning'),
            );
        } catch (InvalidActionItemTransitionException) {
            $this->addFlash(
                'error',
                $this->translator->trans('planning.action_item.error.invalid_transition', [], 'planning'),
            );
        }

        return $this->redirectToRoute('app_planning_action_item_index');
    }
}
