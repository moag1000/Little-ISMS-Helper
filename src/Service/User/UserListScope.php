<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User;
use App\Exception\Tenant\TenantNotFoundException;
use App\Repository\UserRepository;
use App\Security\Voter\UserVoter;
use App\Service\Job\JobTenantScope;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * The one place that decides which users an actor sees in the user list —
 * and therefore in every user list derived from it: the user index, the
 * synchronous and asynchronous user CSV export and the guided-tour report.
 *
 * Rule (= UserVoter::VIEW over all users):
 *  - ROLE_SUPER_ADMIN → every user (with or without an organisation)
 *  - ROLE_ADMIN → users of its own organisation and its subsidiaries
 *    (TenantContext::canAccessTenant / TenantOwnership::isSameOrDescendant)
 *  - holding roles / other accounts with `user.view_all` → their UserVoter
 *    record scope (own organisation; holding roles also subsidiaries)
 *
 * The async export runs without a security token, so the dispatcher resolves
 * the scope here ({@see exportArgsForCurrentUser()}) into the organisation ids of
 * the visible users; the job reads exactly those ({@see usersForJobArgs()})
 * and fails closed without a usable scope. Visibility below ROLE_SUPER_ADMIN
 * depends only on the user's organisation, so the id list reproduces the set.
 */
final readonly class UserListScope
{
    public const string ARG_TENANT_IDS = 'tenantIds';

    public function __construct(
        private Security $security,
        private UserRepository $userRepository,
    ) {
    }

    /**
     * @return list<User>
     */
    public function visibleUsers(): array
    {
        /** @var list<User> $users */
        $users = $this->userRepository->findAllWithRoles();
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return $users;
        }

        return array_values(array_filter(
            $users,
            fn (User $candidate): bool => $this->security->isGranted(UserVoter::VIEW, $candidate),
        ));
    }

    /**
     * Export scope of the current actor, as job args — also the gate of the
     * synchronous export: an account that sees no organisation exports nothing.
     *
     * @return array{allTenants: true}|array{tenantIds: list<int>}
     *
     * @throws AccessDeniedException when the actor sees no organisation's users
     */
    public function exportArgsForCurrentUser(): array
    {
        return $this->exportScopeForCurrentUser()[1];
    }

    /**
     * The visible users AND the export args from ONE voter pass — the sync
     * export writes the users, the args are its gate.
     *
     * @return array{0: list<User>, 1: array{allTenants: true}|array{tenantIds: list<int>}}
     *
     * @throws AccessDeniedException when the actor sees no organisation's users
     */
    public function exportScopeForCurrentUser(): array
    {
        $visible = $this->visibleUsers();
        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return [$visible, [JobTenantScope::ARG_ALL_TENANTS => true]];
        }

        $tenantIds = [];
        foreach ($visible as $user) {
            $id = $user->getTenant()?->getId();
            if ($id !== null) {
                $tenantIds[$id] = $id;
            }
        }
        if ($tenantIds === []) {
            throw new AccessDeniedException('A user export requires an organisation context.');
        }
        sort($tenantIds);

        return [$visible, [self::ARG_TENANT_IDS => $tenantIds]];
    }

    /**
     * The users a running export job may write — fails closed.
     *
     * @param array<string, mixed> $args
     * @return list<User>
     *
     * @throws TenantNotFoundException when the args carry no usable scope
     */
    public function usersForJobArgs(array $args): array
    {
        if (($args[JobTenantScope::ARG_ALL_TENANTS] ?? null) === true) {
            /** @var list<User> */
            return $this->userRepository->findAllWithRoles();
        }

        // Jobs queued before tenantIds existed carry a single tenantId. A
        // tenant deleted since then simply matches no user (empty export —
        // fail closed), where the old path threw TenantNotFoundException.
        $raw = $args[self::ARG_TENANT_IDS] ?? null;
        if ($raw === null && isset($args[JobTenantScope::ARG_TENANT_ID])) {
            $raw = [$args[JobTenantScope::ARG_TENANT_ID]];
        }
        if (!is_array($raw) || $raw === []) {
            throw new TenantNotFoundException(
                null,
                'Refusing to run the user export without a tenant scope: it would read across all organisations.',
            );
        }

        $tenantIds = [];
        foreach ($raw as $id) {
            if (!is_int($id) && !(is_string($id) && ctype_digit($id))) {
                throw new TenantNotFoundException(is_scalar($id) ? (string) $id : get_debug_type($id));
            }
            $tenantIds[] = (int) $id;
        }

        return $this->userRepository->findByTenantIdsWithRoles($tenantIds);
    }
}
