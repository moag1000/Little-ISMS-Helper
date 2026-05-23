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
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;

/**
 * Junior-ISB-Audit C3-01 (S14 Cluster C, 2026-05-23) — Auto-Audience-Assignment
 * on Training mandatory-flag flip.
 *
 * ISO 27001 Cl. 7.3 (Awareness) + A.6.3 (Information security awareness,
 * education and training): when a training is flagged `mandatory = true`,
 * the organisation must be able to identify and prove the audience. The
 * pre-existing {@see AutoReactionTrainingAssignListener} only handles the
 * forward direction (new User → assign existing mandatory trainings).
 * This listener closes the reverse gap:
 *
 *   - new mandatory Training → backfill existing tenant users
 *   - existing Training flipped to mandatory → backfill existing tenant users
 *
 * Audience-resolution mirrors the existing listener:
 *   - `getMandatoryForRoles()` when present → role-intersect with each user
 *   - otherwise fan-out across every active user of the tenant
 *
 * Each missing assignment lands as a {@see TrainingParticipation}
 * (status=pending, assignmentSource=auto:mandatory_assign) row so the
 * §7.3 audit-trail can answer the regulator's question "who was scheduled
 * to take training X" via SELECT instead of LIKE-matching free text.
 *
 * Tenant scope: only the training's own tenant is considered.
 * Idempotent: a pre-existing TrainingParticipation row for
 * (training, user) is left untouched (its current status is preserved).
 *
 * Toggle: {@see AutoReactionService::KEY_TRAINING_ASSIGN} — shared with
 * the existing User-direction listener (operationally the two are one
 * Awareness feature; flipping the toggle off must disable both
 * directions to avoid surprise).
 */
#[AsEntityListener(event: Events::postPersist, entity: Training::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Training::class)]
final class AutoReactionTrainingAudienceAssignListener
{
    public function __construct(
        private readonly AutoReactionService $reactions,
        private readonly LoggerInterface $logger,
        private readonly ?EmailNotificationService $emailNotifier = null,
    ) {
    }

    public function postPersist(Training $training, PostPersistEventArgs $args): void
    {
        $this->maybeAssignAudience($training, $args);
    }

    public function postUpdate(Training $training, PostUpdateEventArgs $args): void
    {
        $this->maybeAssignAudience($training, $args);
    }

    private function maybeAssignAudience(Training $training, mixed $args): void
    {
        if (!$this->reactions->isEnabled(AutoReactionService::KEY_TRAINING_ASSIGN)) {
            return;
        }
        if (!$training->isMandatory()) {
            return;
        }

        $tenant = $training->getTenant();
        if ($tenant === null) {
            // Cannot tenant-scope without a tenant — skip rather than risk
            // cross-tenant leakage of assignment rows.
            return;
        }

        try {
            $em = $args->getObjectManager();
            $userRepo = $em->getRepository(User::class);
            $participationRepo = $em->getRepository(TrainingParticipation::class);

            $users = $userRepo->findBy([
                'isActive' => true,
                'tenant' => $tenant,
            ]);

            $created = 0;
            $assignedUsers = [];
            foreach ($users as $user) {
                /** @var User $user */
                if (!$this->shouldAssign($training, $user)) {
                    continue;
                }
                $existing = $participationRepo->findOneBy([
                    'training' => $training,
                    'user' => $user,
                ]);
                if ($existing instanceof TrainingParticipation) {
                    // Idempotent re-run: skip already-assigned users.
                    continue;
                }

                $participation = new TrainingParticipation();
                $participation->setTenant($tenant);
                $participation->setTraining($training);
                $participation->setUser($user);
                $participation->setStatus(TrainingParticipation::STATUS_PENDING);
                $participation->setAssignmentSource('auto:mandatory_assign');

                $em->persist($participation);
                $created++;
                $assignedUsers[] = $user;
            }
            if ($created > 0) {
                $em->flush();
                $this->logger->info(
                    'Auto-assigned mandatory training to existing tenant users',
                    [
                        'training_id' => $training->getId(),
                        'tenant_id' => $tenant->getId(),
                        'count' => $created,
                    ]
                );

                // Best-effort notification fan-out — ISO 27001 Cl. 7.4.
                $this->notifyAudience($training, $assignedUsers);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-Audience-Assignment failed', [
                'training_id' => $training->getId(),
                'tenant_id' => $tenant->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Audience resolution: role-intersect when the training carries an
     * explicit mandatory-for-roles list, fan-out otherwise. Mirrors
     * {@see AutoReactionTrainingAssignListener::shouldAssign()} so both
     * directions stay consistent.
     */
    private function shouldAssign(Training $training, User $user): bool
    {
        if (method_exists($training, 'getMandatoryForRoles')) {
            $roles = $training->getMandatoryForRoles();
            if (is_array($roles) && !empty($roles)) {
                return count(array_intersect($roles, $user->getRoles())) > 0;
            }
        }
        return true;
    }

    /**
     * @param list<User> $users
     */
    private function notifyAudience(Training $training, array $users): void
    {
        if ($this->emailNotifier === null || $users === []) {
            return;
        }
        try {
            $this->emailNotifier->sendGenericNotification(
                sprintf(
                    'Mandatory training assigned: %s',
                    (string) ($training->getTitle() ?? '—')
                ),
                'emails/auto_reaction_training_assigned.html.twig',
                [
                    'trainings' => [$training],
                    // Template iterates over a single user via the
                    // `user` context; sendGenericNotification fans out
                    // per-recipient and we re-use the per-user variable
                    // by passing the audience batch into the context.
                    'audience' => $users,
                ],
                $users,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Auto-Audience notification failed', [
                'training_id' => $training->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
