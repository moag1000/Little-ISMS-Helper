<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\User;
use App\Security\Persona\Persona;
use App\Security\Persona\PersonaResolver;
use App\Service\GuidedTourService;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFunction;

/**
 * Persona of the logged-in user for templates ({@see PersonaResolver}).
 *
 * - persona(): current Persona (preview included) or null
 * - persona_landing_route(): route the user lands on after login
 * - persona_tours(): tours for the tour menu, own persona first
 * - suggested_tour(): meta of the persona's tour while it is not completed
 */
final readonly class PersonaExtension
{
    public function __construct(
        private PersonaResolver $personaResolver,
        private GuidedTourService $guidedTourService,
        private Security $security,
    ) {
    }

    #[AsTwigFunction('persona')]
    public function persona(): ?Persona
    {
        return $this->personaResolver->current();
    }

    #[AsTwigFunction('persona_landing_route')]
    public function landingRoute(): ?string
    {
        $user = $this->security->getUser();

        return $user === null ? null : $this->personaResolver->landingRouteFor($user);
    }

    /** @return list<string> */
    #[AsTwigFunction('persona_tours')]
    public function tours(): array
    {
        $user = $this->security->getUser();

        return $user === null ? [] : $this->personaResolver->toursFor($user);
    }

    /** @return array{id: string, label_key: string, description_key: string, step_count: int, duration_min: int}|null */
    #[AsTwigFunction('suggested_tour')]
    public function suggestedTour(): ?array
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }
        $tour = $this->guidedTourService->autoDetectTour($user);

        return $user->hasCompletedTour($tour) ? null : $this->guidedTourService->metaFor($tour);
    }
}
