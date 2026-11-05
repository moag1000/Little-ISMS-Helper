<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\MyDay\MyDayInboxEnricher;
use App\Service\MyDayAggregator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Mein Tag" — the one inbox.
 *
 * Everything waiting for the current user, from all modules, with approvals
 * and four-eyes requests decided in place. Risk owners and auditors land here
 * after login (LandingRoute).
 */
class MyDayController extends AbstractController
{
    public function __construct(
        private readonly MyDayAggregator $aggregator,
        private readonly MyDayInboxEnricher $enricher,
    ) {
    }

    #[Route('/my-day', name: 'app_my_day', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = $this->enricher->enrich($this->aggregator->aggregate($user));

        return $this->render('my_day/index.html.twig', [
            'inbox' => $payload,
        ]);
    }
}
