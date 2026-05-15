<?php

declare(strict_types=1);

namespace App\Controller\Notification;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\User;
use App\Repository\Notification\NotificationDeliveryRepository;
use App\Service\ModuleConfigurationService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * In-app notification center for regular users.
 *
 * Routes:
 *   GET  /notifications           — full-page notification center
 *   GET  /notifications/bell      — JSON last 20 unread deliveries (bell polling)
 *   POST /notifications/mark-read — mark all as read (updates User.lastSeenNotifications)
 *
 * The bell endpoint returns JSON consumed by notification_bell_controller.js
 * which polls every 30 s.
 */
#[Route('/notifications', name: 'app_notification_')]
#[IsGranted('ROLE_USER')]
class InAppNotificationCenterController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly NotificationDeliveryRepository $deliveryRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
    ) {}

    /**
     * Route alias: /notifications/center → /notifications (permanent 301).
     * Some E2E suites and bookmarks use the /center suffix — redirect gracefully.
     */
    #[Route('/center', name: 'center_alias', methods: ['GET'])]
    public function centerAlias(): Response
    {
        return $this->redirectToRoute('app_notification_center', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('', name: 'center', methods: ['GET'])]
    public function center(): Response
    {
        if ($redirect = $this->checkModuleActive('notifications')) {
            return $redirect;
        }

        /** @var User $user */
        $user        = $this->getUser();
        $tenant      = $user->getTenant();
        $lastSeen    = $user->getLastSeenNotifications();

        // Fetch all recent in-app deliveries for this tenant, newest first
        $deliveries = $this->getInAppDeliveries($tenant, 100);

        // Group by severity from rule event context
        $grouped = [
            'critical' => [],
            'high'     => [],
            'medium'   => [],
            'low'      => [],
            'other'    => [],
        ];

        foreach ($deliveries as $delivery) {
            $payload  = $delivery->getResponsePayload() ?? [];
            $severity = (string) ($payload['severity'] ?? 'other');
            $key      = array_key_exists($severity, $grouped) ? $severity : 'other';
            $grouped[$key][] = $delivery;
        }

        return $this->render('notification_center/index.html.twig', [
            'deliveries' => $deliveries,
            'grouped'    => $grouped,
            'lastSeen'   => $lastSeen,
        ]);
    }

    #[Route('/bell', name: 'bell', methods: ['GET'])]
    public function bell(): JsonResponse
    {
        if (!$this->moduleService->isModuleActive('notifications')) {
            return new JsonResponse(['count' => 0, 'items' => []]);
        }

        /** @var User $user */
        $user     = $this->getUser();
        $tenant   = $user->getTenant();
        $lastSeen = $user->getLastSeenNotifications();

        $deliveries = $this->getInAppDeliveries($tenant, 20);

        $unreadCount = 0;
        $items       = [];

        foreach ($deliveries as $delivery) {
            $isUnread = $lastSeen === null || ($delivery->getAttemptedAt() !== null && $delivery->getAttemptedAt() > $lastSeen);
            if ($isUnread) {
                $unreadCount++;
            }

            $payload  = $delivery->getResponsePayload() ?? [];
            $ruleName = (string) ($payload['rule_name'] ?? '');
            $items[] = [
                'id'         => $delivery->getId(),
                'rule'       => $ruleName,
                'eventType'  => (string) ($payload['event_type'] ?? ''),
                'status'     => $delivery->getStatus(),
                'attemptedAt'=> $delivery->getAttemptedAt()?->format('c'),
                'isUnread'   => $isUnread,
            ];
        }

        return new JsonResponse([
            'count' => $unreadCount,
            'items' => $items,
        ]);
    }

    #[Route('/mark-read', name: 'mark_read', methods: ['POST'])]
    #[IsCsrfTokenValid('notification_mark_read')]
    public function markRead(): Response
    {
        if (!$this->moduleService->isModuleActive('notifications')) {
            return $this->redirectToRoute('app_dashboard');
        }

        /** @var User $user */
        $user = $this->getUser();
        $user->setLastSeenNotifications(new DateTimeImmutable());
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans(
            'notification.bell.marked_all_read',
            [],
            'notification',
        ));

        return $this->redirectToRoute('app_notification_center');
    }

    /**
     * @return NotificationDelivery[]
     */
    private function getInAppDeliveries(?object $tenant, int $limit): array
    {
        if ($tenant === null) {
            return [];
        }

        return $this->deliveryRepository->createQueryBuilder('d')
            ->join('d.channel', 'c')
            ->where('d.tenant = :tenant')
            ->andWhere('c.type = :type')
            ->andWhere('d.status = :status')
            ->setParameter('tenant', $tenant)
            ->setParameter('type', NotificationChannel::TYPE_IN_APP)
            ->setParameter('status', NotificationDelivery::STATUS_SENT)
            ->orderBy('d.attemptedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
