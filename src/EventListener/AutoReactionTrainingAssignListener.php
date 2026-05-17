<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Training;
use App\Entity\TrainingParticipation;
use App\Entity\User;
use App\Service\AutoReactionService;
use App\Service\EmailNotificationService;
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
 * Audit V3 W2-C3 fix:
 *   - Tenant scoping: only Trainings of the new user's own tenant are
 *     considered. Previously {@see Training::class} was queried with
 *     `findBy(['mandatory' => true])` which leaked ALL tenants'
 *     mandatory trainings into the assignment loop — the loop's
 *     `setParticipants` write then mutated other tenants' Training rows.
 *   - Structured M:N persistence: assignments are recorded in
 *     {@see TrainingParticipation} (status=pending) instead of an
 *     append-only free-text marker. Audit trail queries can answer
 *     "was user X assigned mandatory training Y" via SELECT instead of
 *     LIKE-matching against the free-text `participants` column.
 *
 * Toggle: AutoReactionService::KEY_TRAINING_ASSIGN (default true).
 */
#[AsEntityListener(event: Events::postPersist, entity: User::class)]
final class AutoReactionTrainingAssignListener
{
    public function __construct(
        private readonly AutoReactionService $reactions,
        private readonly LoggerInterface $logger,
        private readonly ?EmailNotificationService $emailNotifier = null,
    ) {
    }

    public function postPersist(User $user, PostPersistEventArgs $args): void
    {
        if (!$this->reactions->isEnabled(AutoReactionService::KEY_TRAINING_ASSIGN)) {
            return;
        }

        $tenant = $user->getTenant();
        if ($tenant === null) {
            // Cannot tenant-scope without a tenant — skip rather than risk
            // cross-tenant leakage.
            return;
        }

        try {
            $em = $args->getObjectManager();
            $trainingRepo = $em->getRepository(Training::class);
            $participationRepo = $em->getRepository(TrainingParticipation::class);

            $trainings = $trainingRepo->findBy([
                'mandatory' => true,
                'tenant' => $tenant,
            ]);

            $assigned = 0;
            $assignedTrainings = [];
            foreach ($trainings as $training) {
                /** @var Training $training */
                if (!$this->shouldAssign($training, $user)) {
                    continue;
                }
                // Skip if already assigned (idempotent re-runs).
                $existing = $participationRepo->findOneBy([
                    'training' => $training,
                    'user' => $user,
                ]);
                if ($existing instanceof TrainingParticipation) {
                    continue;
                }

                $participation = new TrainingParticipation();
                $participation->setTenant($tenant);
                $participation->setTraining($training);
                $participation->setUser($user);
                $participation->setStatus(TrainingParticipation::STATUS_PENDING);
                $participation->setAssignmentSource('auto:user_create');

                $em->persist($participation);
                $assigned++;
                $assignedTrainings[] = $training;
            }
            if ($assigned > 0) {
                $em->flush();
                $this->logger->info('Auto-assigned mandatory trainings to new user', [
                    'user_id' => $user->getId(),
                    'tenant_id' => $tenant->getId(),
                    'count' => $assigned,
                ]);

                // V3 W2-H4 (ISO 27001 Cl.7.4 / A.6.3): Notify trainee.
                $this->notifyTrainee($user, $assignedTrainings);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-training-assignment failed', [
                'user_id' => $user->getId(),
                'tenant_id' => $tenant->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * V3 W2-H4 — Send a single email summarising all auto-assigned mandatory
     * trainings to the new user. Best-effort: failures must not block the
     * assignment persistence.
     *
     * @param list<Training> $trainings
     */
    private function notifyTrainee(User $user, array $trainings): void
    {
        if ($this->emailNotifier === null || empty($trainings)) {
            return;
        }
        if ($user->getEmail() === null || $user->getEmail() === '') {
            return;
        }
        try {
            $this->emailNotifier->sendGenericNotification(
                sprintf('You have been assigned %d mandatory training(s)', count($trainings)),
                'emails/auto_reaction_training_assigned.html.twig',
                [
                    'user' => $user,
                    'trainings' => $trainings,
                ],
                [$user]
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-Training-Assignment notification failed', [
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
