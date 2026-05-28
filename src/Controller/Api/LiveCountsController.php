<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\LiveCountAggregator;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Phase 4.4 — Live-Badge polling endpoint.
 *
 * Returns tenant-scoped badge counts for the current user. Used by the
 * Stimulus `live-badge` controller which polls this endpoint every 30 seconds
 * and updates sidebar badge spans (Mein Tag, Aktivität, Inbox, Approvals).
 *
 * WCAG 2.2 SC 4.1.3 — badge spans use aria-live="polite" so screen readers
 * announce count changes without disrupting the user's current focus.
 *
 * Response is cached for 5 seconds (max-age) to reduce DB pressure when
 * multiple browser tabs are open simultaneously.
 *
 * @see App\Service\LiveCountAggregator
 * @see assets/controllers/live_badge_controller.js
 */
#[Route('/api/live-counts', name: 'api_live_counts', methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class LiveCountsController extends AbstractController
{
    public function __construct(
        private readonly LiveCountAggregator $aggregator,
        private readonly TenantContext $tenantContext,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        $counts = $this->aggregator->getCounts($user, $tenant);

        $response = new JsonResponse($counts);

        // 5-second cache: reduces DB load for multi-tab users while keeping badges fresh
        $response->setMaxAge(5);
        $response->setSharedMaxAge(0); // private only — tenant-scoped data
        $response->headers->set('Cache-Control', 'private, max-age=5');
        $response->headers->set('X-Accel-Expires', '5'); // nginx FastCGI cache support

        return $response;
    }
}
