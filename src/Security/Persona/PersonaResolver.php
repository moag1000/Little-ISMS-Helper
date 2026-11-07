<?php

declare(strict_types=1);

namespace App\Security\Persona;

use App\Service\ModuleConfigurationService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * "Which persona am I" — the one place that maps a user to a {@see Persona}.
 *
 * Two questions, kept apart on purpose:
 *  - identity ({@see primaryOf()}): the persona a user lands as. Decided on
 *    the roles that were ASSIGNED (stored roles plus system custom roles),
 *    so an admin who also holds ROLE_DPO is a DPO, and an admin without a
 *    persona role is an admin — not the CISO the role hierarchy would make
 *    of every admin.
 *  - access ({@see personasOf()}, {@see reaches()}): everything the user may
 *    do, through the role hierarchy (an ISB is also a manager, an admin is
 *    every persona).
 *
 * The compliance manager's persona preview ("acting as", session key
 * {@see ACTING_AS_SESSION_KEY}) changes the current persona of the request.
 *
 * Responsibility for a single record ("who owns this risk") is a separate
 * question and belongs to a record-owner resolver, which can fall back to
 * {@see reaches()} for role-based responsibility.
 */
final class PersonaResolver
{
    public const string ACTING_AS_SESSION_KEY = 'compliance.acting_as_persona';

    /** @var array<string, list<string>> reachable roles per user, request-local */
    private array $reachableCache = [];

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
        private readonly ?Security $security = null,
        private readonly ?RequestStack $requestStack = null,
        private readonly ?ModuleConfigurationService $moduleConfiguration = null,
    ) {
    }

    /** The persona the user lands as (no session preview). */
    public function primaryOf(UserInterface $user): Persona
    {
        $assigned = $user->getRoles();
        foreach (Persona::cases() as $persona) {
            if (array_intersect($persona->roles(), $assigned) !== []) {
                return $persona;
            }
        }

        return Persona::USER;
    }

    /**
     * Personas whose role is assigned to the user directly (no hierarchy):
     * "who holds the ISB role" for the ISMS role register (ISO 27001 5.3).
     *
     * @return list<Persona>
     */
    public function assignedPersonasOf(UserInterface $user): array
    {
        $assigned = $user->getRoles();

        return array_values(array_filter(
            Persona::cases(),
            static fn (Persona $p): bool => array_intersect($p->roles(), $assigned) !== [],
        ));
    }

    /**
     * Every persona the user holds through the role hierarchy, in priority order.
     *
     * @return list<Persona>
     */
    public function personasOf(UserInterface $user): array
    {
        $reachable = $this->reachableRoles($user);

        return array_values(array_filter(
            Persona::cases(),
            static fn (Persona $p): bool => array_intersect($p->roles(), $reachable) !== [],
        ));
    }

    /** Does the user reach (directly or through the hierarchy) any of these roles? */
    public function reaches(UserInterface $user, string ...$roles): bool
    {
        return array_intersect($roles, $this->reachableRoles($user)) !== [];
    }

    /** @return list<string> */
    public function reachableRoles(UserInterface $user): array
    {
        $key = $user->getUserIdentifier() . '|' . implode(',', $user->getRoles());

        return $this->reachableCache[$key] ??= array_values(array_unique(
            $this->roleHierarchy->getReachableRoleNames($user->getRoles()),
        ));
    }

    /**
     * Roles reachable from a plain role list (role hierarchy), for questions
     * about part of a user's roles.
     *
     * @param list<string> $roles
     * @return list<string>
     */
    public function reachableRolesOf(array $roles): array
    {
        return array_values(array_unique($this->roleHierarchy->getReachableRoleNames($roles)));
    }

    /** May the user open the dashboard of this persona (own role, hierarchy or preview)? */
    public function canSeeDashboardOf(UserInterface $user, Persona $persona): bool
    {
        if ($this->actingAs() === $persona) {
            return true;
        }

        $own = $this->personasOf($user);
        foreach ($persona->dashboardAudience() as $audience) {
            if (in_array($audience, $own, true)) {
                return true;
            }
        }

        return false;
    }

    /** May the user download this report ({@see \App\Security\Voter\ReportAccessVoter})? */
    public function mayDownload(UserInterface $user, string $reportAttribute): bool
    {
        foreach ($this->personasOf($user) as $persona) {
            if (in_array($reportAttribute, $persona->reports(), true)) {
                return true;
            }
        }

        return false;
    }

    /** Persona previewed by the compliance manager in this session, if any. */
    public function actingAs(): ?Persona
    {
        $request = $this->requestStack?->getCurrentRequest();
        if ($request === null || !$request->hasSession()) {
            return null;
        }
        $value = $request->getSession()->get(self::ACTING_AS_SESSION_KEY);

        return is_string($value) ? Persona::fromVoterAttribute($value) : null;
    }

    /** Persona of the logged-in user for this request, preview included. */
    public function current(): ?Persona
    {
        $user = $this->security?->getUser();
        if ($user === null) {
            return null;
        }

        return $this->actingAs() ?? $this->primaryOf($user);
    }

    /** The persona's dashboard, or the main dashboard when its module is off. */
    public function dashboardRouteFor(Persona $persona): string
    {
        $module = $persona->dashboardModule();
        if ($module !== null && $this->moduleConfiguration !== null
            && !$this->moduleConfiguration->isModuleActive($module)) {
            return 'app_dashboard';
        }

        return $persona->dashboardRoute();
    }

    /** Where the user lands after login. */
    public function landingRouteFor(UserInterface $user): string
    {
        $persona = $this->primaryOf($user);

        return $persona->landsOnMyDay() ? 'app_my_day' : $this->dashboardRouteFor($persona);
    }

    /**
     * Distinct tours that fit the user, own persona first, the basic tour
     * last (for the tour menu). Generic roles every persona inherits
     * (manager, auditor) add no tour of their own.
     *
     * @return list<string>
     */
    public function toursFor(UserInterface $user): array
    {
        $tours = [$this->primaryOf($user)->tour()];
        foreach ($this->personasOf($user) as $persona) {
            if (!in_array($persona, [Persona::MANAGER, Persona::AUDITOR, Persona::USER], true)) {
                $tours[] = $persona->tour();
            }
        }
        $tours[] = Persona::USER->tour();

        return array_values(array_unique($tours));
    }
}
