<?php

declare(strict_types=1);

namespace App\Controller\Notification;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\User;
use App\Repository\Notification\NotificationDeliveryRepository;
use App\Repository\ObligationNoticeRepository;
use App\Util\ListPage;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
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
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/notifications', name: 'app_notification_')]
#[IsGranted('ROLE_USER')]
class InAppNotificationCenterController extends AbstractController
{
    public function __construct(
        private readonly NotificationDeliveryRepository $deliveryRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly ObligationNoticeRepository $notices,
    ) {}

    /**
     * Route alias: /notifications/center → /notifications (permanent 301).
     * Some E2E suites and bookmarks use the /center suffix — redirect gracefully.
     */
    // @no-module-gate-required: pure redirect to app_notification_center, which is gated itself.
    #[Route('/center', name: 'center_alias', methods: ['GET'])]
    public function centerAlias(): Response
    {
        return $this->redirectToRoute('app_notification_center', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    #[Route('', name: 'center', methods: ['GET'])]
    public function center(Request $request): Response
    {
        /** @var User $user */
        $user        = $this->getUser();
        $tenant      = $user->getTenant();
        $lastSeen    = $user->getLastSeenNotifications();

        // Fetch all recent in-app deliveries for this tenant, newest first
        $deliveries = $this->getInAppDeliveries($tenant, 100);

        $severityOf = static fn (NotificationDelivery $d): string => (string) (($d->getResponsePayload() ?? [])['severity'] ?? 'other');

        $severity = $request->query->getString('severity', '');
        if (in_array($severity, ['critical', 'high', 'medium', 'low'], true)) {
            $deliveries = array_values(array_filter($deliveries, static fn (NotificationDelivery $d): bool => $severityOf($d) === $severity));
        }

        return $this->render('notification_center/index.html.twig', [
            'page'           => ListPage::fromRequest(
                $deliveries,
                $request,
                static fn (NotificationDelivery $d): array => [
                    ($d->getResponsePayload() ?? [])['rule_name'] ?? null,
                    ($d->getResponsePayload() ?? [])['event_type'] ?? null,
                ],
            ),
            'critical_count' => count(array_filter($deliveries, static fn (NotificationDelivery $d): bool => $severityOf($d) === 'critical')),
            'high_count'     => count(array_filter($deliveries, static fn (NotificationDelivery $d): bool => $severityOf($d) === 'high')),
            'lastSeen'       => $lastSeen,
            'notices'        => $this->notices->latestFor($user, 20),
        ]);
    }

    /**
     * The bell: the user's own reminders (deadline digests, urgent statutory
     * deadlines) first, then the organisation's rule notifications.
     */
    #[Route('/bell', name: 'bell', methods: ['GET'])]
    public function bell(): JsonResponse
    {
        /** @var User $user */
        $user     = $this->getUser();
        $lastSeen = $user->getLastSeenNotifications();
        $isUnread = static fn (?\DateTimeInterface $at): bool => $lastSeen === null || ($at !== null && $at > $lastSeen);

        $items = [];
        foreach ($this->notices->latestFor($user, 10) as $notice) {
            $items[] = [
                'id'          => 'notice-' . $notice->getId(),
                'rule'        => $notice->getTitle(),
                'eventType'   => $notice->getKind(),
                'status'      => 'sent',
                'attemptedAt' => $notice->getCreatedAt()->format('c'),
                'isUnread'    => $isUnread($notice->getCreatedAt()),
                'link'        => $notice->getLink(),
            ];
        }
        foreach ($this->getInAppDeliveries($user->getTenant(), 20) as $delivery) {
            $payload = $delivery->getResponsePayload() ?? [];
            $items[] = [
                'id'          => $delivery->getId(),
                'rule'        => (string) ($payload['rule_name'] ?? ''),
                'eventType'   => (string) ($payload['event_type'] ?? ''),
                'status'      => $delivery->getStatus(),
                'attemptedAt' => $delivery->getAttemptedAt()?->format('c'),
                'isUnread'    => $isUnread($delivery->getAttemptedAt()),
                'link'        => null,
            ];
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) $b['attemptedAt'], (string) $a['attemptedAt']));

        return new JsonResponse([
            'count' => count(array_filter($items, static fn (array $i): bool => $i['isUnread'])),
            'items' => $items,
        ]);
    }

    #[Route('/mark-read', name: 'mark_read', methods: ['POST'])]
    #[IsCsrfTokenValid('notification_mark_read')]
    public function markRead(): Response
    {
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
            ->orderBy('d.attemptedAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
