<?php

declare(strict_types=1);

namespace App\Controller;

use App\AlvaHint\AlvaHintService;
use App\Entity\User;
use App\Repository\AlvaHintDismissalRepository;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AJAX endpoint for dismissing a proactive Alva-Fee hint plus admin
 * telemetry view. Dismissals persist in the DB so the same user does
 * not see the hint again regardless of which device they log in from.
 */
class AlvaHintController extends AbstractController
{
    public function __construct(
        private readonly AlvaHintService $alvaHintService,
        private readonly AuditLogger $auditLogger,
        private readonly ?AlvaHintDismissalRepository $dismissalRepository = null,
        private readonly ?TenantContext $tenantContext = null,
    ) {
    }

    #[Route('/alva-hint/dismiss', name: 'app_alva_hint_dismiss', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function dismiss(Request $request): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(['error' => 'no_user'], 401);
        }

        $hintKey = (string) $request->request->get('hint_key');
        $entityType = (string) $request->request->get('entity_type', '');
        $entityId = (int) $request->request->get('entity_id', 0);
        $token = (string) $request->request->get('_token');

        if ($hintKey === '' || !$this->isCsrfTokenValid('alva-hint-' . $hintKey, $token)) {
            return new JsonResponse(['error' => 'invalid_token'], 400);
        }

        $until = null;
        $snoozeDays = (int) $request->request->get('snooze_days', 0);
        if ($snoozeDays > 0 && $snoozeDays <= 365) {
            $until = (new DateTimeImmutable())->modify(sprintf('+%d days', $snoozeDays));
        }

        $this->alvaHintService->dismiss(
            $user,
            $user->getTenant(),
            $hintKey,
            $entityType,
            $entityId,
            $until,
        );

        $this->auditLogger->logCustom(
            'alva_hint.dismissed',
            'AlvaHintDismissal',
            null,
            null,
            [
                'hint_key' => $hintKey,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'source' => 'alva_hint',
            ],
            sprintf('Dismissed Alva hint %s on %s#%d', $hintKey, $entityType, $entityId),
        );

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/admin/alva-hint/stats', name: 'app_alva_hint_stats', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function stats(): Response
    {
        if ($this->dismissalRepository === null || $this->tenantContext === null) {
            throw $this->createNotFoundException('Dismissal repository unavailable.');
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $stats = $this->dismissalRepository->statisticsByTenant($tenant);

        return $this->render('admin/alva_hint_stats.html.twig', [
            'stats' => $stats,
            'tenant' => $tenant,
        ]);
    }
}
