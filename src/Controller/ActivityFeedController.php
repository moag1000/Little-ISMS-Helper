<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Service\ActivityFeed;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
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

    /** Valid scope values for the ?scope= query parameter (V4-EF-6). */
    private const VALID_SCOPES = ['all', 'compliance'];

    #[Route('/activity-feed', name: 'app_activity_feed', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // V4-EF-6: optional ?scope=compliance filter.
        $scope = $request->query->get('scope', 'all');
        if (!in_array($scope, self::VALID_SCOPES, true)) {
            $scope = 'all';
        }

        $items = $this->activityFeed->recent($user, 50, $scope);

        return $this->render('activity_feed/index.html.twig', [
            'items'         => $items,
            'current_scope' => $scope,
        ]);
    }
}
