<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Throttles the unauthenticated bearer-token URLs (Trust Center posture page,
 * supplier questionnaire) per client IP. The tokens are 256 bit and cannot be
 * guessed, but without a limit a leaked link could be hammered — the posture
 * page builds a snapshot per hit, the questionnaire accepts every POST.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 10)]
final class PublicTokenRateLimitSubscriber
{
    private const array PATH_PREFIXES = ['/trust/', '/supplier-questionnaire/'];

    public function __construct(
        private readonly RateLimiterFactory $publicTokenLimiter,
    ) {
    }

    public function onKernelRequest(RequestEvent $requestEvent): void
    {
        if (!$requestEvent->isMainRequest()) {
            return;
        }

        $path = $requestEvent->getRequest()->getPathInfo();
        $matches = array_filter(self::PATH_PREFIXES, static fn (string $p): bool => str_starts_with($path, $p));
        if ($matches === []) {
            return;
        }

        $limit = $this->publicTokenLimiter
            ->create('public-token-' . ($requestEvent->getRequest()->getClientIp() ?? 'unknown'))
            ->consume(1);
        if ($limit->isAccepted()) {
            return;
        }

        $response = new Response('Too many requests.', Response::HTTP_TOO_MANY_REQUESTS, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
        ]);
        $requestEvent->setResponse($response);
    }
}
