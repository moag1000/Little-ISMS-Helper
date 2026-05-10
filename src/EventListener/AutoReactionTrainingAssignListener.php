<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Training;
use App\Entity\User;
use App\Service\AutoReactionService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Audit V3 C3 — Auto-Training-Assignment.
 *
 * On User postPersist: assign all mandatory active Trainings whose
 * required-roles set intersects the new user's role list.
 *
 * Best-effort: relies on Training::isMandatory() and Training role/audience
 * fields if present — if the entity does not expose mandatory-for-roles
 * granularity, all mandatory trainings are linked.
 *
 * Toggle: AutoReactionService::KEY_TRAINING_ASSIGN (default true).
 */
#[AsEntityListener(event: Events::postPersist, entity: User::class)]
class AutoReactionTrainingAssignListener
{
    public function __construct(
        private readonly AutoReactionService $reactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postPersist(User $user, PostPersistEventArgs $args): void
    {
        if (!$this->reactions->isEnabled(AutoReactionService::KEY_TRAINING_ASSIGN)) {
            return;
        }

        try {
            $em = $args->getObjectManager();
            $trainings = $em->getRepository(Training::class)->findBy([
                'mandatory' => true,
            ]);

            $assigned = 0;
            $userTag = sprintf('[user:%d:%s]', $user->getId() ?? 0, trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? '')));
            foreach ($trainings as $training) {
                /** @var Training $training */
                if (!$this->shouldAssign($training, $user)) {
                    continue;
                }
                // Training in this codebase tracks participants as free-text string.
                // Append a tagged marker for downstream attendance tracking.
                $current = $training->getParticipants() ?? '';
                if (str_contains($current, $userTag)) {
                    continue;
                }
                $training->setParticipants(trim($current . "\n" . $userTag));
                $em->persist($training);
                $assigned++;
            }
            if ($assigned > 0) {
                $em->flush();
                $this->logger->info('Auto-assigned mandatory trainings to new user', [
                    'user_id' => $user->getId(),
                    'count' => $assigned,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-training-assignment failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function shouldAssign(Training $training, User $user): bool
    {
        // If training entity carries mandatoryForRoles or similar, intersect.
        if (method_exists($training, 'getMandatoryForRoles')) {
            $roles = $training->getMandatoryForRoles();
            if (is_array($roles) && !empty($roles)) {
                return count(array_intersect($roles, $user->getRoles())) > 0;
            }
        }
        // Default: every mandatory training is assigned to every new user.
        return true;
    }
}
