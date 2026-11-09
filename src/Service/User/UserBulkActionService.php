<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\Role;
use App\Entity\User;
use App\Security\UserAdministrationGuard;
use App\Security\Voter\UserVoter;
use App\Service\AuditLogger;
use App\Service\InitialAdminService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Bulk actions of the user administration (activate, deactivate, assign_role,
 * delete). `User` is not covered by the AuditLogSubscriber, so every applied
 * change goes into one {@see AuditLogger::logBulk()} batch (ISO 27001
 * Cl. 7.5.3). The initial setup admin and the acting user are never
 * deactivated or deleted.
 */
final readonly class UserBulkActionService
{
    public function __construct(
        private Security $security,
        private EntityManagerInterface $entityManager,
        private AuditLogger $auditLogger,
        private InitialAdminService $initialAdminService,
        private UserAdministrationGuard $userAdministrationGuard,
    ) {}

    /**
     * @param iterable<User> $users
     * @return array{count: int, skipped: int, skipped_reasons: array<string, int>, batch_id: ?string}
     */
    public function apply(string $action, iterable $users, ?User $actor, ?Role $role = null): array
    {
        $count = 0;
        $skipped = 0;
        $skippedReasons = [];
        $perEntityData = [];

        foreach ($users as $user) {
            $userId = $user->getId();
            $skipReason = $this->skipReason($action, $user, $actor, $role);
            if ($skipReason !== null) {
                $skipped++;
                $skippedReasons[$skipReason] = ($skippedReasons[$skipReason] ?? 0) + 1;
                continue;
            }

            $row = ['action' => 'update', 'entity_id' => $userId];
            switch ($action) {
                case 'activate':
                case 'deactivate':
                    $row['old_values'] = ['isActive' => $user->isActive()];
                    $user->setIsActive($action === 'activate');
                    $user->setUpdatedAt(new DateTimeImmutable());
                    $row['new_values'] = ['isActive' => $action === 'activate'];
                    break;
                case 'assign_role':
                    \assert($role instanceof Role);
                    $user->addCustomRole($role);
                    $user->setUpdatedAt(new DateTimeImmutable());
                    $row['new_values'] = ['customRole' => $role->getName()];
                    break;
                case 'delete':
                    $row['action'] = 'delete';
                    $row['old_values'] = ['email' => $user->getEmail()];
                    $this->entityManager->remove($user);
                    break;
            }
            $perEntityData[] = $row;
            $count++;
        }

        $this->entityManager->flush();

        $batchId = null;
        if ($perEntityData !== []) {
            $batchId = $this->auditLogger->logBulk(
                eventType: 'bulk_user_' . $action,
                entityType: 'User',
                batchData: ['action' => $action, 'count' => $count, 'skipped' => $skipped],
                perEntityData: $perEntityData,
                description: sprintf('Bulk user action "%s": %d users', $action, $count),
            );
        }

        return ['count' => $count, 'skipped' => $skipped, 'skipped_reasons' => $skippedReasons, 'batch_id' => $batchId];
    }

    private function skipReason(string $action, User $user, ?User $actor, ?Role $role): ?string
    {
        $isCurrentUser = $actor instanceof User && $actor->getId() !== null && $actor->getId() === $user->getId();

        return match ($action) {
            'activate' => $this->security->isGranted(UserVoter::EDIT, $user) ? null : 'no_permission',
            'deactivate', 'delete' => match (true) {
                !$this->security->isGranted($action === 'delete' ? UserVoter::DELETE : UserVoter::EDIT, $user)
                    => 'no_permission',
                $this->initialAdminService->isInitialAdmin($user) => 'initial_admin',
                $isCurrentUser => 'current_user',
                default => null,
            },
            'assign_role' => match (true) {
                !$role instanceof Role => 'role_not_found',
                // Role ceiling (S14): only roles the actor may grant to this account.
                !$this->userAdministrationGuard->mayOfferCustomRole($role)
                    || !$this->userAdministrationGuard->mayAssignRoleName((string) $role->getName(), $user),
                !$this->security->isGranted(UserVoter::EDIT, $user) => 'no_permission',
                $user->getCustomRoles()->contains($role) => 'already_has_role',
                default => null,
            },
            default => 'unknown_action',
        };
    }
}
