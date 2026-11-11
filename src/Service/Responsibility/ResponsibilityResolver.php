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
 *      but not merely because they are admins), then the wider circle of
 *      {@see Responsibility::thenRoles()}
 *   4. the tenant's admins, so a duty never reaches nobody — unless only the
 *      role may act ({@see Responsibility::withoutAdminFallback()})
 *
 * A record several deadline sources know (a supplier due for reassessment
 * whose contract ends) is the responsibility of everybody the strongest step
 * of any of them names — not of whichever source was registered first.
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

    /** @var array<string, list<User>> responsible accounts per record, request-local */
    private array $responsibleByRecord = [];

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
        $this->responsibleByRecord = [];
    }

    /**
     * @return list<User>
     */
    public function resolve(Responsibility $responsibility, Tenant $tenant): array
    {
        $users = $this->resolveSteps($responsibility, $tenant, 0);
        if ($users !== [] || !$this->allowsAdminFallback($responsibility, 0)) {
            return $users;
        }

        return $this->usersHolding($tenant, ...self::ADMIN_ROLES) ?: $this->usersReaching($tenant, ...self::ADMIN_ROLES);
    }

    /** False when the duty (or every source of the record it points to) may only be discharged by its role. */
    private function allowsAdminFallback(Responsibility $responsibility, int $depth): bool
    {
        if (!$responsibility->adminFallback) {
            return false;
        }
        if ($responsibility->record === null || $depth >= 3
            || $responsibility->holders !== [] || $responsibility->deputies !== [] || $responsibility->roles !== []) {
            return true;
        }
        $sources = $this->sources->allForRecord($responsibility->record);
        foreach ($sources as $source) {
            if ($this->allowsAdminFallback($source->responsibleOf($responsibility->record), $depth + 1)) {
                return true;
            }
        }

        return $sources === [];
    }

    /**
     * Steps 0-3; empty when none applies (the caller falls back to admins).
     *
     * @return list<User>
     */
    private function resolveSteps(Responsibility $responsibility, Tenant $tenant, int $depth): array
    {
        return $this->resolveTiered($responsibility, $tenant, $depth)[1];
    }

    /**
     * Steps 0-3 with the step that answered (1 holders, 2 deputies, 3 roles,
     * 4 wider roles; 0 = none). For a record several sources know, the
     * strongest step any of them reaches wins and everybody named on that
     * step counts: the risk owner of the review and the owner the acceptance
     * names are both responsible, the risk managers a source falls back to
     * are not while an owner is set.
     *
     * @return array{int, list<User>}
     */
    private function resolveTiered(Responsibility $responsibility, Tenant $tenant, int $depth): array
    {
        if ($responsibility->record !== null && $depth < 3) {
            $best = 0;
            $viaRecord = [];
            foreach ($this->sources->allForRecord($responsibility->record) as $source) {
                [$tier, $users] = $this->resolveTiered($source->responsibleOf($responsibility->record), $tenant, $depth + 1);
                if ($tier === 0 || ($best !== 0 && $tier > $best)) {
                    continue;
                }
                if ($best === 0 || $tier < $best) {
                    $best = $tier;
                    $viaRecord = [];
                }
                foreach ($users as $user) {
                    $viaRecord[$user->getId() !== null ? 'id' . $user->getId() : 'o' . spl_object_id($user)] ??= $user;
                }
            }
            if ($viaRecord !== []) {
                return [$best, array_values($viaRecord)];
            }
        }
        foreach ([1 => $responsibility->holders, 2 => $responsibility->deputies] as $tier => $people) {
            $accounts = $this->accounts($people, $tenant);
            if ($accounts !== []) {
                return [$tier, $accounts];
            }
        }
        foreach ([3 => $responsibility->roles, 4 => $responsibility->fallbackRoles] as $tier => $roles) {
            $holding = $roles !== [] ? $this->usersHolding($tenant, ...$roles) : [];
            if ($holding !== []) {
                return [$tier, $holding];
            }
        }

        return [0, []];
    }

    /**
     * Everybody a reminder of this duty goes to: whoever acts ({@see resolve()})
     * plus the people and role holders kept informed.
     *
     * @return list<User>
     */
    public function recipients(Responsibility $responsibility, Tenant $tenant): array
    {
        $users = [];
        foreach ([
            $this->resolve($responsibility, $tenant),
            $this->accounts($responsibility->informed, $tenant),
            $responsibility->informedRoles !== [] ? $this->usersHolding($tenant, ...$responsibility->informedRoles) : [],
        ] as $group) {
            foreach ($group as $user) {
                $users[$user->getId() !== null ? 'id' . $user->getId() : 'o' . spl_object_id($user)] ??= $user;
            }
        }

        return array_values($users);
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
     * Who is responsible for this record: everybody the deadline sources that
     * know it name on the strongest step any of them reaches (the answer must
     * not depend on the order sources are registered).
     * Empty when no source knows the record or it has no tenant.
     *
     * @return list<User>
     */
    public function responsibleFor(object $record): array
    {
        $tenant = method_exists($record, 'getTenant') ? $record->getTenant() : null;
        if ($this->sources->allForRecord($record) === [] || !$tenant instanceof Tenant) {
            return [];
        }

        return $this->resolve(Responsibility::ofRecord($record), $tenant);
    }

    /**
     * Whether the user is one who has to act on this record (holder, deputy or
     * fallback role of its deadline source, admins as last resort). The answer
     * behind the `RECORD_RESPONSIBLE` voter attribute: "role OR responsible".
     */
    public function isResponsibleFor(User $user, object $record): bool
    {
        $id = method_exists($record, 'getId') ? $record->getId() : null;
        $key = $record::class . '#' . (is_scalar($id) ? (string) $id : 'o' . spl_object_id($record));
        foreach ($this->responsibleByRecord[$key] ??= $this->responsibleFor($record) as $candidate) {
            if ($candidate === $user || ($candidate->getId() !== null && $candidate->getId() === $user->getId())) {
                return true;
            }
        }

        return false;
    }

    /**
     * S28: responsible through the record itself — holder, deputy or holder of the
     * duty's role — and not merely as admin of last resort. Decisions of the DPO or of
     * top management accept this kind only (an organisation without a DPO does not
     * make its admins the DPO).
     */
    public function isResponsibleByRecord(User $user, object $record): bool
    {
        $tenant = method_exists($record, 'getTenant') ? $record->getTenant() : null;
        if ($this->sources->allForRecord($record) === [] || !$tenant instanceof Tenant) {
            return false;
        }
        foreach ($this->resolveSteps(Responsibility::ofRecord($record), $tenant, 0) as $candidate) {
            if ($candidate === $user || ($candidate->getId() !== null && $candidate->getId() === $user->getId())) {
                return true;
            }
        }

        return false;
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
            // A holder must belong to this very organisation: tenant-less and
            // foreign accounts never receive reminders or approvals.
            $userTenant = $user->getTenant();
            if ($userTenant === null
                || ($userTenant !== $tenant && ($userTenant->getId() === null || $userTenant->getId() !== $tenant->getId()))) {
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
