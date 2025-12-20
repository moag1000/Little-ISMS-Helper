<?php

namespace App\Service;

use App\Entity\PushSubscription;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\PushSubscriptionRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Web Push Notification Service
 *
 * Handles sending push notifications to subscribed PWA clients.
 * Uses the Web Push API with VAPID authentication.
 */
class WebPushService
{
    private const VAPID_SUBJECT = 'mailto:admin@little-isms-helper.local';

    public function __construct(
        private readonly PushSubscriptionRepository $subscriptionRepository,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Get VAPID public key for client-side subscription
     */
    public function getVapidPublicKey(): ?string
    {
        $keys = $this->getVapidKeys();
        return $keys['publicKey'] ?? null;
    }

    /**
     * Subscribe a user to push notifications
     */
    public function subscribe(
        User $user,
        Tenant $tenant,
        string $endpoint,
        string $publicKey,
        string $authToken,
        ?string $userAgent = null
    ): PushSubscription {
        // Check if subscription already exists
        $existing = $this->subscriptionRepository->findByEndpoint($endpoint);

        if ($existing) {
            // Update existing subscription
            $existing->setUser($user);
            $existing->setTenant($tenant);
            $existing->setPublicKey($publicKey);
            $existing->setAuthToken($authToken);
            $existing->setUserAgent($userAgent);
            $existing->setDeviceName($this->parseDeviceName($userAgent));
            $existing->setIsActive(true);
            $existing->resetFailureCount();
            $this->subscriptionRepository->save($existing, true);

            $this->logger->info('Updated push subscription', [
                'user_id' => $user->getId(),
                'device' => $existing->getDeviceName(),
            ]);

            return $existing;
        }

        // Create new subscription
        $subscription = new PushSubscription();
        $subscription->setUser($user);
        $subscription->setTenant($tenant);
        $subscription->setEndpoint($endpoint);
        $subscription->setPublicKey($publicKey);
        $subscription->setAuthToken($authToken);
        $subscription->setUserAgent($userAgent);
        $subscription->setDeviceName($this->parseDeviceName($userAgent));

        $this->subscriptionRepository->save($subscription, true);

        $this->logger->info('Created push subscription', [
            'user_id' => $user->getId(),
            'device' => $subscription->getDeviceName(),
        ]);

        return $subscription;
    }

    /**
     * Unsubscribe from push notifications
     */
    public function unsubscribe(string $endpoint): bool
    {
        $subscription = $this->subscriptionRepository->findByEndpoint($endpoint);

        if (!$subscription) {
            return false;
        }

        $this->subscriptionRepository->remove($subscription, true);

        $this->logger->info('Removed push subscription', [
            'subscription_id' => $subscription->getId(),
        ]);

        return true;
    }

    /**
     * Send push notification to a specific user
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): int
    {
        $subscriptions = $this->subscriptionRepository->findActiveByUser($user);
        return $this->sendToSubscriptions($subscriptions, $title, $body, $data);
    }

    /**
     * Send push notification to all users in a tenant
     */
    public function sendToTenant(Tenant $tenant, string $title, string $body, array $data = []): int
    {
        $subscriptions = $this->subscriptionRepository->findActiveByTenant($tenant);
        return $this->sendToSubscriptions($subscriptions, $title, $body, $data);
    }

    /**
     * Send push notification to specific subscriptions
     *
     * @param PushSubscription[] $subscriptions
     */
    public function sendToSubscriptions(array $subscriptions, string $title, string $body, array $data = []): int
    {
        $keys = $this->getVapidKeys();
        if (!$keys) {
            $this->logger->warning('VAPID keys not configured, cannot send push notifications');
            return 0;
        }

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'icon' => '/icons/icon-192x192.png',
            'badge' => '/icons/icon-72x72.png',
            'data' => array_merge($data, [
                'timestamp' => time(),
            ]),
        ]);

        $successCount = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $success = $this->sendPush($subscription, $payload, $keys);

                if ($success) {
                    $subscription->markAsUsed();
                    $successCount++;
                } else {
                    $subscription->incrementFailureCount();
                }

                $this->subscriptionRepository->save($subscription, true);
            } catch (\Exception $e) {
                $this->logger->error('Failed to send push notification', [
                    'subscription_id' => $subscription->getId(),
                    'error' => $e->getMessage(),
                ]);
                $subscription->incrementFailureCount();
                $this->subscriptionRepository->save($subscription, true);
            }
        }

        return $successCount;
    }

    /**
     * Send a push notification using Web Push protocol
     */
    private function sendPush(PushSubscription $subscription, string $payload, array $keys): bool
    {
        $endpoint = $subscription->getEndpoint();
        $userPublicKey = $subscription->getPublicKey();
        $userAuthToken = $subscription->getAuthToken();

        // For now, use a simple HTTP request
        // In production, you would use a library like minishlink/web-push
        // or implement full Web Push encryption

        try {
            // Create JWT for VAPID
            $jwt = $this->createVapidJwt($endpoint, $keys['privateKey']);

            // Encrypt payload (simplified - in production use proper Web Push encryption)
            $encryptedPayload = $this->encryptPayload($payload, $userPublicKey, $userAuthToken);

            // Send to push service
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $encryptedPayload['ciphertext'],
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/octet-stream',
                    'Content-Encoding: aes128gcm',
                    'Authorization: vapid t=' . $jwt . ', k=' . $keys['publicKey'],
                    'TTL: 86400',
                    'Urgency: normal',
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // 201 Created = success, 410 Gone = subscription expired
            if ($httpCode === 410) {
                $subscription->setIsActive(false);
                return false;
            }

            return $httpCode >= 200 && $httpCode < 300;
        } catch (\Exception $e) {
            $this->logger->error('Push send failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Create VAPID JWT token
     */
    private function createVapidJwt(string $endpoint, string $privateKey): string
    {
        $parsedUrl = parse_url($endpoint);
        $audience = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

        $header = json_encode(['typ' => 'JWT', 'alg' => 'ES256']);
        $payload = json_encode([
            'aud' => $audience,
            'exp' => time() + 86400,
            'sub' => self::VAPID_SUBJECT,
        ]);

        $headerB64 = $this->base64UrlEncode($header);
        $payloadB64 = $this->base64UrlEncode($payload);
        $dataToSign = $headerB64 . '.' . $payloadB64;

        // Sign with private key (ES256)
        $signature = '';
        $privateKeyResource = openssl_pkey_get_private($privateKey);
        if ($privateKeyResource) {
            openssl_sign($dataToSign, $signature, $privateKeyResource, OPENSSL_ALGO_SHA256);
        }

        return $dataToSign . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * Encrypt payload using Web Push encryption
     * Simplified implementation - in production use minishlink/web-push
     */
    private function encryptPayload(string $payload, string $userPublicKey, string $authToken): array
    {
        // This is a simplified placeholder
        // Real implementation requires ECDH key exchange and proper AES-128-GCM encryption
        // For production, use the minishlink/web-push library

        return [
            'ciphertext' => $payload,
            'salt' => random_bytes(16),
            'publicKey' => $userPublicKey,
        ];
    }

    /**
     * Get VAPID keys from environment or generate new ones
     */
    private function getVapidKeys(): ?array
    {
        $publicKey = $_ENV['VAPID_PUBLIC_KEY'] ?? null;
        $privateKey = $_ENV['VAPID_PRIVATE_KEY'] ?? null;

        if ($publicKey && $privateKey) {
            return [
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ];
        }

        // Try to read from file
        $keyFile = $this->projectDir . '/var/vapid_keys.json';
        if (file_exists($keyFile)) {
            $keys = json_decode(file_get_contents($keyFile), true);
            if ($keys && isset($keys['publicKey'], $keys['privateKey'])) {
                return $keys;
            }
        }

        // Generate new keys and save
        return $this->generateVapidKeys();
    }

    /**
     * Generate new VAPID key pair
     */
    public function generateVapidKeys(): ?array
    {
        try {
            // Generate EC key pair for VAPID
            $config = [
                'curve_name' => 'prime256v1',
                'private_key_type' => OPENSSL_KEYTYPE_EC,
            ];

            $key = openssl_pkey_new($config);
            if (!$key) {
                return null;
            }

            $details = openssl_pkey_get_details($key);
            openssl_pkey_export($key, $privateKeyPem);

            // Extract the raw public key bytes
            $publicKeyBytes = $details['ec']['x'] . $details['ec']['y'];
            $publicKeyB64 = $this->base64UrlEncode(chr(4) . $publicKeyBytes);

            $keys = [
                'publicKey' => $publicKeyB64,
                'privateKey' => $privateKeyPem,
            ];

            // Save to file
            $keyFile = $this->projectDir . '/var/vapid_keys.json';
            file_put_contents($keyFile, json_encode($keys, JSON_PRETTY_PRINT));
            chmod($keyFile, 0600);

            $this->logger->info('Generated new VAPID keys');

            return $keys;
        } catch (\Exception $e) {
            $this->logger->error('Failed to generate VAPID keys', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Parse device name from user agent
     */
    private function parseDeviceName(?string $userAgent): ?string
    {
        if (!$userAgent) {
            return null;
        }

        // Simple parsing for common browsers
        $browser = 'Unknown';
        $os = 'Unknown';

        if (str_contains($userAgent, 'Chrome')) {
            $browser = 'Chrome';
        } elseif (str_contains($userAgent, 'Firefox')) {
            $browser = 'Firefox';
        } elseif (str_contains($userAgent, 'Safari')) {
            $browser = 'Safari';
        } elseif (str_contains($userAgent, 'Edge')) {
            $browser = 'Edge';
        }

        if (str_contains($userAgent, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($userAgent, 'Mac OS')) {
            $os = 'macOS';
        } elseif (str_contains($userAgent, 'Linux')) {
            $os = 'Linux';
        } elseif (str_contains($userAgent, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($userAgent, 'iOS') || str_contains($userAgent, 'iPhone')) {
            $os = 'iOS';
        }

        return "{$browser} on {$os}";
    }

    /**
     * Base64 URL-safe encoding
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
