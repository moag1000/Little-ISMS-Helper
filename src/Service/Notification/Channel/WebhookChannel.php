<?php

declare(strict_types=1);

namespace App\Service\Notification\Channel;

use App\Entity\Notification\NotificationChannel;
use App\Entity\Notification\NotificationDelivery;
use App\Entity\Notification\NotificationRule;
use App\Service\Sso\SecretEncryptionInterface;
use App\Validator\Constraint\NoInternalIp;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Delivers notifications via HTTP POST webhook.
 *
 * Channel config keys:
 *   url:        string — target webhook URL (REQUIRED)
 *   timeout:    int    — request timeout in seconds (default: 10)
 *
 * HMAC-SHA256 signature is computed over the JSON payload using the channel
 * secret (stored encrypted in secretEncrypted). Sent as
 * X-Signature: sha256=<hex-digest>
 *
 * SSRF-protection: target URL is validated against NoInternalIp constraint
 * before every request. Refuses to fire if URL resolves to private/reserved IP.
 */
class WebhookChannel
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly SecretEncryptionInterface $encryption,
        private readonly ValidatorInterface $validator,
    ) {}

    /**
     * @param array<string, mixed> $entityState
     */
    public function deliver(
        NotificationRule $rule,
        NotificationChannel $channel,
        NotificationDelivery $delivery,
        array $entityState,
    ): void {
        $config  = $channel->getConfig();
        $url     = (string) ($config['url'] ?? '');
        $timeout = (int) ($config['timeout'] ?? 10);

        if ($url === '') {
            $delivery->markFailed('notification.error.webhook_internal_ip', []);
            return;
        }

        // SSRF guard — validate URL before resolving
        $violations = $this->validator->validate($url, [new NoInternalIp()]);
        if (count($violations) > 0) {
            $delivery->markFailed('notification.error.webhook_internal_ip', ['url' => $url]);
            return;
        }

        $payload = json_encode([
            'event_type'   => $rule->getEventType(),
            'rule_name'    => $rule->getName(),
            'entity_state' => $entityState,
            'delivered_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        $headers = ['Content-Type' => 'application/json'];

        // Attach HMAC signature when a secret is configured
        $encryptedSecret = $channel->getSecretEncrypted();
        if ($encryptedSecret !== null) {
            $plainSecret = $this->encryption->decrypt($encryptedSecret);
            if ($plainSecret !== null) {
                $sig = hash_hmac('sha256', $payload, $plainSecret);
                $headers['X-Signature'] = 'sha256=' . $sig;
            }
        }

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'body'    => $payload,
                'timeout' => $timeout,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $delivery->markSent(['status_code' => $statusCode, 'url' => $url]);
            } else {
                $delivery->markFailed(
                    sprintf('Webhook returned HTTP %d', $statusCode),
                    ['status_code' => $statusCode, 'url' => $url],
                );
            }
        } catch (ExceptionInterface | TransportException $e) {
            $delivery->markFailed($e->getMessage(), ['url' => $url]);
        }
    }
}
