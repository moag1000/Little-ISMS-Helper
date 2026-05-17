<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\MyDayAggregator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Audit V3 C1 — "Mein Tag" zentrale Inbox.
 *
 * Aggregates open items for the current user across 7 distributed inboxes
 * (workflow/pending + workflow/overdue, four_eyes/inbox, policy_ack/inbox,
 *  audit_findings, DSRs, corrective_actions overdue).
 */
class MyDayController extends AbstractController
{
    public function __construct(
        private readonly MyDayAggregator $aggregator,
    ) {
    }

    #[Route('/my-day', name: 'app_my_day', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $payload = $this->aggregator->aggregate($user);

        return $this->render('my_day/index.html.twig', [
            'inbox' => $payload,
        ]);
    }
}
