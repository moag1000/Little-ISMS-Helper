<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\GuidedTourService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sprint 13 — Guided Tour Endpoints.
 *
 *  - GET  /tour/steps/{role}    Step-Liste als JSON (mit übersetzten Title/Body)
 *  - POST /tour/complete/{id}   markiert Tour als durchlaufen
 *  - POST /tour/dismiss-banner  Banner ausblenden bis zum nächsten relevanten Update
 */
#[IsGranted('ROLE_USER')]
class GuidedTourController extends AbstractController
{
    public function __construct(
        private readonly GuidedTourService $tourService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly RequestStack $requestStack,
    ) {
    }

    #[Route('/tour/steps/{role}', name: 'app_guided_tour_steps', methods: ['GET'], requirements: ['role' => '[a-z_]+'])]
    public function steps(string $role): JsonResponse
    {
        if (!in_array($role, GuidedTourService::ALL_TOURS, true)) {
            return new JsonResponse(['error' => 'unknown tour'], 404);
        }

        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'de';
        $steps = [];
        foreach ($this->tourService->stepsFor($role) as $step) {
            // P5: Tenant-Override hat Priorität über Translation-Default.
            $override = $this->tourService->resolveOverride($role, $step['id'], $locale);
            $title = $override !== null && $override['title'] !== ''
                ? $override['title']
                : $this->translator->trans($step['title_key'], [], 'guided_tour');
            $body = $override !== null && $override['body'] !== ''
                ? $override['body']
                : $this->translator->trans($step['body_key'], [], 'guided_tour');

            $steps[] = [
                'id' => $step['id'],
                'target' => $step['target'],
                'title' => $title,
                'body' => $body,
                'url' => $step['url'],
                'placement' => $step['placement'],
            ];
        }

        return new JsonResponse([
            'tour_id' => $role,
            'steps' => $steps,
        ]);
    }

    #[Route('/tour/complete/{role}', name: 'app_guided_tour_complete', methods: ['POST'], requirements: ['role' => '[a-z_]+'])]
    public function complete(string $role, Request $request): JsonResponse
    {
        $token = (string) $request->request->get('_token', $request->headers->get('X-CSRF-Token', ''));
        if (!$this->isCsrfTokenValid('guided_tour_complete', $token)) {
            return new JsonResponse(['error' => 'invalid csrf token'], 403);
        }
        if (!in_array($role, GuidedTourService::ALL_TOURS, true)) {
            return new JsonResponse(['error' => 'unknown tour'], 404);
        }

        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'no user'], 401);
        }

        $user->markTourCompleted($role);
        $this->entityManager->flush();

        return new JsonResponse([
            'completed_tours' => $user->getCompletedTours(),
        ]);
    }

    #[Route('/tour/reset/{role}', name: 'app_guided_tour_reset', methods: ['POST'], requirements: ['role' => '[a-z_]+'])]
    public function reset(string $role, Request $request): JsonResponse
    {
        $token = (string) $request->request->get('_token', $request->headers->get('X-CSRF-Token', ''));
        if (!$this->isCsrfTokenValid('guided_tour_reset', $token)) {
            return new JsonResponse(['error' => 'invalid csrf token'], 403);
        }

        /** @var User|null $user */
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'no user'], 401);
        }

        if ($role === 'all') {
            $user->resetAllTours();
        } elseif (in_array($role, GuidedTourService::ALL_TOURS, true)) {
            $user->resetTour($role);
        } else {
            return new JsonResponse(['error' => 'unknown tour'], 404);
        }

        $this->entityManager->flush();

        return new JsonResponse([
            'completed_tours' => $user->getCompletedTours(),
        ]);
    }
}
