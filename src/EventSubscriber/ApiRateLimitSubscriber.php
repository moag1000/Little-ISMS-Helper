<?php

namespace App\EventSubscriber;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * API Rate Limit Subscriber
 *
 * Protects API endpoints from abuse and DoS attacks through IP-based rate limiting.
 * Implements OWASP API Security Top 10: API4:2023 Unrestricted Resource Consumption.
 *
 * Features:
 * - IP-based rate limiting (100 requests/minute default)
 * - Automatic 429 Too Many Requests responses
 * - RFC-compliant rate limit headers
 * - Retry-After header for client guidance
 * - Applies only to /api/* routes
 *
 * Rate Limit Headers:
 * - X-RateLimit-Limit: Maximum requests allowed
 * - X-RateLimit-Remaining: Requests remaining in window
 * - X-RateLimit-Reset: Unix timestamp when limit resets
 * - Retry-After: Seconds until retry allowed
 *
 * Configuration:
 * - Limiter: apiLimiter (configured in rate_limiter.yaml)
 * - Strategy: Token bucket with 100 tokens/minute
 */
class ApiRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RateLimiterFactory $rateLimiterFactory
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $requestEvent): void
    {
        $request = $requestEvent->getRequest();

        // Only apply to API routes
        if (!str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        // Create limiter based on client IP
        $limiter = $this->rateLimiterFactory->create($request->getClientIp());

        // Try to consume 1 token
        $limit = $limiter->consume(1);

        if (false === $limit->isAccepted()) {
            // Rate limit exceeded
            $jsonResponse = new JsonResponse([
                'error' => 'Too many requests',
                'message' => 'API rate limit exceeded. Please try again later.',
                'retry_after' => $limit->getRetryAfter()->getTimestamp(),
            ], Response::HTTP_TOO_MANY_REQUESTS);

            // Add rate limit headers
            $jsonResponse->headers->set('X-RateLimit-Limit', (string) $limit->getLimit());
            $jsonResponse->headers->set('X-RateLimit-Remaining', '0');
            $jsonResponse->headers->set('X-RateLimit-Reset', (string) $limit->getRetryAfter()->getTimestamp());
            $jsonResponse->headers->set('Retry-After', (string) $limit->getRetryAfter()->getTimestamp());

            $requestEvent->setResponse($jsonResponse);

            return;
        }

        // Add rate limit headers to successful requests
        $requestEvent->getRequest()->attributes->set('_rate_limit', [
            'limit' => $limit->getLimit(),
            'remaining' => $limit->getRemainingTokens(),
            'reset' => $limit->getRetryAfter()->getTimestamp(),
        ]);
    }
}
