<?php

namespace App\Controller\Api;

use App\Service\TenantContext;
use App\Service\WebPushService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Push Subscription API Controller
 *
 * Handles PWA push notification subscription management.
 */
#[Route('/api/push')]
class PushSubscriptionController extends AbstractController
{
    public function __construct(
        private readonly WebPushService $webPushService,
        private readonly TenantContext $tenantContext,
    ) {
    }

    /**
     * Get VAPID public key for client-side subscription
     */
    #[Route('/vapid-public-key', name: 'api_push_vapid_key', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function getVapidPublicKey(): JsonResponse
    {
        $publicKey = $this->webPushService->getVapidPublicKey();

        if (!$publicKey) {
            return $this->json([
                'error' => 'Push notifications not configured',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return $this->json([
            'publicKey' => $publicKey,
        ]);
    }

    /**
     * Subscribe to push notifications
     */
    #[Route('/subscribe', name: 'api_push_subscribe', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function subscribe(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json([
                'error' => 'Invalid JSON payload',
            ], Response::HTTP_BAD_REQUEST);
        }

        // Validate required fields
        $endpoint = $data['endpoint'] ?? null;
        $keys = $data['keys'] ?? [];
        $publicKey = $keys['p256dh'] ?? null;
        $authToken = $keys['auth'] ?? null;

        if (!$endpoint || !$publicKey || !$authToken) {
            return $this->json([
                'error' => 'Missing required subscription data',
                'required' => ['endpoint', 'keys.p256dh', 'keys.auth'],
            ], Response::HTTP_BAD_REQUEST);
        }

        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();

        if (!$tenant) {
            return $this->json([
                'error' => 'No tenant context',
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $subscription = $this->webPushService->subscribe(
                $user,
                $tenant,
                $endpoint,
                $publicKey,
                $authToken,
                $request->headers->get('User-Agent')
            );

            return $this->json([
                'success' => true,
                'subscription_id' => $subscription->getId(),
                'device' => $subscription->getDeviceName(),
            ], Response::HTTP_CREATED);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to create subscription',
                'message' => $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Unsubscribe from push notifications
     */
    #[Route('/unsubscribe', name: 'api_push_unsubscribe', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $endpoint = $data['endpoint'] ?? null;

        if (!$endpoint) {
            return $this->json([
                'error' => 'Missing endpoint',
            ], Response::HTTP_BAD_REQUEST);
        }

        $success = $this->webPushService->unsubscribe($endpoint);

        return $this->json([
            'success' => $success,
        ]);
    }

    /**
     * Test push notification (admin only)
     */
    #[Route('/test', name: 'api_push_test', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function testPush(): JsonResponse
    {
        $user = $this->getUser();

        $count = $this->webPushService->sendToUser(
            $user,
            'Test Notification',
            'This is a test push notification from Little ISMS Helper.',
            [
                'type' => 'test',
                'url' => '/dashboard',
            ]
        );

        return $this->json([
            'success' => $count > 0,
            'sent_count' => $count,
        ]);
    }
}
