<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ActivityFeed;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Audit V3 C6 — Activity-Feed Controller.
 */
class ActivityFeedController extends AbstractController
{
    public function __construct(
        private readonly ActivityFeed $activityFeed,
    ) {
    }

    #[Route('/activity-feed', name: 'app_activity_feed')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $items = $this->activityFeed->recent($user, 50);

        return $this->render('activity_feed/index.html.twig', [
            'items' => $items,
        ]);
    }
}
