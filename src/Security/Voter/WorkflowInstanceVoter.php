<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * S33: who may read a running or finished approval chain — its steps, the approver names
 * and the approval, rejection and clarification texts.
 *
 * Granted to
 * - whoever started it;
 * - whoever approved, rejected or answered a step of it (approval history), and whoever
 *   may approve its current step ({@see WorkflowService::canUserApprove()});
 * - ROLE_MANAGER and above, as long as the record the chain runs on is readable for them
 *   ({@see PersonalDataRecordVoter} for a record about a person);
 * - the person responsible for that record (`RECORD_RESPONSIBLE`).
 *
 * A plain account without relation to the chain sees nothing of it; the lists of
 * {@see \App\Controller\WorkflowController} filter with the same attribute.
 */
final class WorkflowInstanceVoter extends Voter
{
    public const string VIEW = 'WORKFLOW_INSTANCE_VIEW';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly EntityManagerInterface $entityManager,
        // Lazy: WorkflowService needs the authorization checker, which collects this voter.
        #[Autowire(lazy: true)]
        private readonly WorkflowService $workflowService,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::VIEW && $subject instanceof WorkflowInstance;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || !$user->isActive() || !$subject instanceof WorkflowInstance) {
            return false;
        }
        $tenant = $subject->getTenant();
        if ($tenant !== null && $user->getTenant() !== null && $tenant->getId() !== $user->getTenant()->getId()
            && !$this->accessDecisionManager->decide($token, ['ROLE_SUPER_ADMIN'])) {
            return false;
        }

        if ($subject->getInitiatedBy()?->getId() === $user->getId()) {
            return true;
        }
        foreach ($subject->getApprovalHistory() ?? [] as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            foreach ($entry as $key => $value) {
                // approver_id, top_management_user_id, witness_user_id, …: a person named in the chain.
                if (is_string($key) && ($key === 'approver_id' || str_ends_with($key, '_user_id'))
                    && is_numeric($value) && (int) $value === $user->getId()) {
                    return true;
                }
            }
        }
        $step = $subject->getCurrentStep();
        if ($step instanceof WorkflowStep && $this->workflowService->canUserApprove($user, $step, $subject)) {
            return true;
        }

        $record = $this->record($subject);
        if ($record !== null && $this->accessDecisionManager->decide($token, [RecordResponsibleVoter::RESPONSIBLE], $record)) {
            return true;
        }
        if (!$this->accessDecisionManager->decide($token, ['ROLE_MANAGER'])) {
            return false;
        }

        return $record === null || $this->accessDecisionManager->decide($token, [PersonalDataRecordVoter::VIEW], $record);
    }

    private function record(WorkflowInstance $instance): ?object
    {
        $class = WorkflowService::subjectClass($instance->getEntityType());
        $id = $instance->getEntityId();
        if ($class === null || $id === null) {
            return null;
        }
        try {
            return $this->entityManager->find($class, $id);
        } catch (\Throwable) {
            return null;
        }
    }
}
