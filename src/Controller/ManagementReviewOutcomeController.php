<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresModule;
use App\Entity\ActionItem;
use App\Entity\ManagementReview;
use App\Entity\User;
use App\Form\ManagementReviewTaskType;
use App\Lifecycle\InvalidTransitionException;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Security\Voter\RecordResponsibleVoter;
use App\Service\ManagementReview\ReviewOutcomeService;
use App\Service\Planning\InvalidActionItemTransitionException;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Results of a management review (ISO 27001 9.3.3) on its detail page: the
 * actions it decided, as tasks with responsible and due date, and the
 * completion by top management — its decision on the results.
 */
// @no-module-gate-required: ISO 27001 9.3.3 outputs: the review (required module) owns its action items, they are not the resource-planning feature
#[RequiresModule('reviews')]
final class ManagementReviewOutcomeController extends AbstractController
{
    private const string WORKFLOW = 'management_review_lifecycle';

    public function __construct(
        private readonly ReviewOutcomeService $outcome,
        private readonly LifecycleTransitionInterface $lifecycle,
    ) {
    }

    #[Route('/management-review/{id}/tasks', name: 'app_management_review_task_add', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function addTask(Request $request, ManagementReview $review): Response
    {
        $task = $this->outcome->newTask($review);
        $form = $this->createForm(ManagementReviewTaskType::class, $task, [
            'action' => $this->generateUrl('app_management_review_task_add', ['id' => $review->getId()]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->outcome->addTask($review, $task);
            $this->addFlash('success', 'management_review.flash.task_added');
        } else {
            $this->addFlash('warning', 'management_review.flash.task_invalid');
        }

        return $this->redirectToRoute('app_management_review_show', ['id' => $review->getId(), '_fragment' => 'tasks']);
    }

    #[Route('/management-review/{id}/tasks/{task}/done', name: 'app_management_review_task_done', requirements: ['id' => '\d+', 'task' => '\d+'], methods: ['POST'])]
    #[IsGranted(new Expression(RecordResponsibleVoter::MANAGER_OR_RESPONSIBLE), subject: 'task')]
    #[IsCsrfTokenValid(new Expression('"mr_task_done_" ~ args["task"].getId()'))]
    public function completeTask(
        #[MapEntity(id: 'id')] ManagementReview $review,
        #[MapEntity(id: 'task')] ActionItem $task,
    ): Response
    {
        $user = $this->getUser();
        if (!$this->outcome->belongsTo($task, $review) || !$user instanceof User) {
            throw $this->createNotFoundException();
        }
        try {
            $this->outcome->completeTask($task, $user);
            $this->addFlash('success', 'management_review.flash.task_done');
        } catch (InvalidActionItemTransitionException) {
            $this->addFlash('warning', 'management_review.flash.task_closed');
        }

        return $this->redirectToRoute('app_management_review_show', ['id' => $review->getId(), '_fragment' => 'tasks']);
    }

    /**
     * Top management approves the results and completes the review (9.3.3);
     * ManagementReviewCompletedListener records it in the decision register.
     */
    #[Route('/management-review/{id}/complete', name: 'app_management_review_complete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_TOP_MGMT')]
    #[IsCsrfTokenValid(new Expression('"mr_complete_" ~ args["review"].getId()'))]
    public function complete(Request $request, ManagementReview $review): Response
    {
        if (!$this->isGranted('lifecycle.' . self::WORKFLOW . '.complete', $review)) {
            throw $this->createAccessDeniedException();
        }
        $user = $this->getUser();
        $comment = trim((string) $request->request->get('comment', ''));
        try {
            $this->lifecycle->transition(
                $review,
                self::WORKFLOW,
                'complete',
                $user instanceof User ? $user : null,
                $comment !== '' ? $comment : null,
            );
            $this->addFlash('success', 'management_review.flash.completed');
        } catch (InvalidTransitionException) {
            $this->addFlash('warning', 'management_review.flash.not_completable');
        }

        return $this->redirectToRoute('app_management_review_show', ['id' => $review->getId()]);
    }
}
