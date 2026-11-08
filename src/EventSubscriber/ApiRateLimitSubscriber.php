<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ApiToken;
use App\Security\ApiTokenAuthenticator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * API Rate Limit Subscriber
 *
 * Protects API endpoints from abuse and DoS attacks through rate limiting.
 * Implements OWASP API Security Top 10: API4:2023 Unrestricted Resource Consumption.
 *
 * Features:
 * - Rate limiting per API token, else per logged-in user, else per client IP
 *   (100 requests/minute default) — runs right after the firewall (priority 7)
 *   so it knows who is asking; one bucket per token, not per shared IP
 * - Covers /api/* and the assistant endpoint /mcp (a JSON-RPC batch there is
 *   capped by McpController and counts once per message via {@see self::consume()})
 * - Automatic 429 Too Many Requests responses
 * - RFC-compliant rate limit headers
 * - Retry-After header for client guidance
 * - Applies to /api/* routes and /mcp
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
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 7)]
final class ApiRateLimitSubscriber
{
    public function __construct(
        private readonly RateLimiterFactory $apiLimiter,
        private readonly Security $security,
    ) {}

    /**
     * Take $tokens more from the caller's bucket (an MCP batch of n messages
     * counts n); false when the bucket is empty.
     */
    public function consume(Request $request, int $tokens): bool
    {
        return $tokens <= 0 || $this->apiLimiter->create($this->keyFor($request))->consume($tokens)->isAccepted();
    }

    public function onKernelRequest(RequestEvent $requestEvent): void
    {
        if (!$requestEvent->isMainRequest()) {
            return; // MCP tool calls reach the API as sub-requests; the MCP call itself was counted
        }
        $request = $requestEvent->getRequest();

        // Only apply to API routes and the assistant endpoint
        if (!str_starts_with($request->getPathInfo(), '/api/') && $request->getPathInfo() !== '/mcp') {
            return;
        }

        // The glossary lookup is a read-only UI helper fired on hover (tiny
        // static responses, client-side cached per acronym). Exempt it from the
        // data-API rate limit so tooltip definitions never 429 on a page with
        // many terms.
        if (str_starts_with($request->getPathInfo(), '/api/glossary/')) {
            return;
        }

        $limiter = $this->apiLimiter->create($this->keyFor($request));

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
            // Retry-After carries seconds (RFC 9110 §10.2.3), not a timestamp.
            $jsonResponse->headers->set(
                'Retry-After',
                (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
            );

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

    /** The bucket: the API token, else the logged-in user, else the client IP. */
    private function keyFor(Request $request): string
    {
        $token = $request->attributes->get(ApiTokenAuthenticator::TOKEN_ATTRIBUTE);
        if ($token instanceof ApiToken && $token->getId() !== null) {
            return 'token:' . $token->getId();
        }
        $user = $this->security->getUser();
        if ($user !== null) {
            return 'user:' . $user->getUserIdentifier();
        }

        return 'ip:' . ($request->getClientIp() ?? 'unknown');
    }
}
