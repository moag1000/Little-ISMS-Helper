<?php

declare(strict_types=1);

namespace App\Controller;

use App\AlvaHint\AlvaHint;
use App\AlvaHint\AlvaHintService;
use App\Service\AuditLogger;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tenant-wide Alva-Fee hint inbox.
 *
 * Lists all active cross-module global hints for the current tenant,
 * grouped by tier, with filter and dismiss support. Accessible at
 * /{locale}/alva-inbox (locale-prefixed via routes.yaml app_routes group).
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/alva-inbox', name: 'app_alva_hint_inbox')]
#[IsGranted('ROLE_USER')]
class AlvaHintInboxController extends AbstractController
{
    public function __construct(
        private readonly AlvaHintService $alvaHintService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    #[Route('', name: '', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $hints = $this->alvaHintService->getAllTenantGlobalHints();
        $tierFilter = (int) $request->query->get('tier', 0);

        // Filter by tier if requested (0 = all)
        $filtered = $tierFilter > 0
            ? array_values(array_filter($hints, static fn(AlvaHint $h): bool => $h->priorityTier === $tierFilter))
            : $hints;

        // Group by tier for display
        $byTier = [1 => [], 2 => [], 3 => []];
        foreach ($filtered as $hint) {
            $byTier[$hint->priorityTier][] = $hint;
        }

        $this->auditLogger->logCustom(
            'alva_hint_rendered',
            'AlvaHintInbox',
            null,
            null,
            [
                'page' => 'inbox',
                'total_hints' => count($hints),
                'filtered_hints' => count($filtered),
                'tier_filter' => $tierFilter,
            ],
            sprintf('Alva inbox rendered: %d hints (tier filter: %d)', count($hints), $tierFilter),
        );

        return $this->render('alva_hint/inbox.html.twig', [
            'hints' => $filtered,
            'byTier' => $byTier,
            'totalCount' => count($hints),
            'tierFilter' => $tierFilter,
        ]);
    }
}
