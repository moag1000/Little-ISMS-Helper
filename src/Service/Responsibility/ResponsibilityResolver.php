<?php

declare(strict_types=1);

namespace App\Service\Responsibility;

use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\Persona\PersonaResolver;
use App\Service\Deadline\DeadlineSourceRegistry;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The one answer to "who has to act on this?".
 *
 * Order (never mixed, the first non-empty step wins):
 *   1. holders on the record (owner, assignee, approver; a Person through its
 *      linked account) — the risk owner gets their risks, nobody else
 *   2. deputies, when no holder resolves to an active account of the tenant
 *   3. users who hold one of the fallback roles themselves (role hierarchy,
 *      but not merely because they are admins)
 *   4. the tenant's admins, so a duty never reaches nobody
 *
 * My Day, the digest mail, the urgent mail and workflow approvers ask only
 * this class. Persona questions ("which dashboard") stay with PersonaResolver.
 */
class ResponsibilityResolver implements ResetInterface
{
    /** Roles that reach everything; holding them is not a reason to be the fallback. */
    private const array ADMIN_ROLES = ['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'];

    /** @var array<int, list<User>> active users per tenant id, request-local */
    private array $usersByTenant = [];

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly PersonaResolver $personaResolver,
        private readonly DeadlineSourceRegistry $sources,
    ) {
    }

    /** Long-running workers (Messenger, scheduler) start each message with fresh user lists. */
    public function reset(): void
    {
        $this->usersByTenant = [];
    }

    /**
     * @return list<User>
     */
    public function resolve(Responsibility $responsibility, Tenant $tenant): array
    {
        return $this->resolveSteps($responsibility, $tenant, 0)
            ?: ($this->usersHolding($tenant, ...self::ADMIN_ROLES) ?: $this->usersReaching($tenant, ...self::ADMIN_ROLES));
    }

    /**
     * Steps 0-3; empty when none applies (the caller falls back to admins).
     *
     * @return list<User>
     */
    private function resolveSteps(Responsibility $responsibility, Tenant $tenant, int $depth): array
    {
        if ($responsibility->record !== null && $depth < 3) {
            $source = $this->sources->forRecord($responsibility->record);
            if ($source !== null) {
                $viaRecord = $this->resolveSteps($source->responsibleOf($responsibility->record), $tenant, $depth + 1);
                if ($viaRecord !== []) {
                    return $viaRecord;
                }
            }
        }
        foreach ([$responsibility->holders, $responsibility->deputies] as $people) {
            $accounts = $this->accounts($people, $tenant);
            if ($accounts !== []) {
                return $accounts;
            }
        }

        return $responsibility->roles !== [] ? $this->usersHolding($tenant, ...$responsibility->roles) : [];
    }

    public function isResponsible(User $user, Responsibility $responsibility, Tenant $tenant): bool
    {
        foreach ($this->resolve($responsibility, $tenant) as $candidate) {
            if ($candidate === $user || ($candidate->getId() !== null && $candidate->getId() === $user->getId())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Who is responsible for this record, via the deadline source it belongs to.
     * Empty when no source knows the record or it has no tenant.
     *
     * @return list<User>
     */
    public function responsibleFor(object $record): array
    {
        $source = $this->sources->forRecord($record);
        $tenant = method_exists($record, 'getTenant') ? $record->getTenant() : null;
        if ($source === null || !$tenant instanceof Tenant) {
            return [];
        }

        return $this->resolve($source->responsibleOf($record), $tenant);
    }

    /**
     * Active users of the tenant who reach one of the roles through the role
     * hierarchy (admins included).
     *
     * @return list<User>
     */
    public function usersReaching(Tenant $tenant, string ...$roles): array
    {
        return array_values(array_filter(
            $this->activeUsers($tenant),
            fn (User $u): bool => $this->personaResolver->reaches($u, ...$roles),
        ));
    }

    /**
     * Active users who hold one of the roles themselves or through a non-admin
     * role (an ISB who is also CISO counts, an admin who inherits ISB does not).
     *
     * @return list<User>
     */
    public function usersHolding(Tenant $tenant, string ...$roles): array
    {
        $adminRequested = array_intersect($roles, self::ADMIN_ROLES) !== [];

        return array_values(array_filter(
            $this->activeUsers($tenant),
            function (User $u) use ($roles, $adminRequested): bool {
                $own = $adminRequested ? $u->getRoles() : array_values(array_diff($u->getRoles(), self::ADMIN_ROLES));
                if ($own === []) {
                    return false;
                }

                return array_intersect($roles, $this->personaResolver->reachableRolesOf($own)) !== [];
            },
        ));
    }

    /**
     * @param list<User|Person> $people
     * @return list<User>
     */
    private function accounts(array $people, Tenant $tenant): array
    {
        $users = [];
        foreach ($people as $person) {
            $user = $person instanceof Person ? $person->getLinkedUser() : $person;
            if ($user === null || !$user->isActive()) {
                continue;
            }
            if ($user->getTenant() !== null && $user->getTenant()->getId() !== $tenant->getId()) {
                continue;
            }
            $users[spl_object_id($user)] = $user;
        }

        return array_values($users);
    }

    /** @return list<User> */
    private function activeUsers(Tenant $tenant): array
    {
        $key = (int) $tenant->getId();

        return $this->usersByTenant[$key] ??= $this->userRepository->findActiveByTenant($tenant);
    }
}
