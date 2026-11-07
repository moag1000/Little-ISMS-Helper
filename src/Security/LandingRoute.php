<?php

declare(strict_types=1);

namespace App\Security;

use App\Security\Persona\PersonaResolver;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Where a user lands after login: the dashboard of their persona.
 *
 * Users who open the tool to act on what is waiting for them land on My Day
 * (risk owners, plain users, auditors). Every other persona lands on its own
 * dashboard: ISB on the ISB cockpit, BCM officer on the BCM cockpit, top
 * management on the board view and so on. The mapping lives in
 * {@see \App\Security\Persona\Persona}.
 */
final readonly class LandingRoute
{
    public function __construct(private PersonaResolver $personaResolver)
    {
    }

    public function routeFor(?UserInterface $user): string
    {
        return $user === null ? 'app_dashboard' : $this->personaResolver->landingRouteFor($user);
    }
}
