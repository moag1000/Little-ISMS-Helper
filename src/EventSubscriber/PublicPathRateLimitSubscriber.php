<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Throttles every path an anonymous visitor can reach, per client IP (no user exists
 * there yet). The bearer tokens of the Trust Center and the supplier questionnaire are
 * 192/256 bit and cannot be guessed, but without a limit a leaked link could be hammered
 * (the posture page builds a snapshot per hit, the questionnaire accepts every POST);
 * the probes query the database, SSO start fetches the IdP discovery document and opens
 * a session, Quick-Fix runs schema checks (S25).
 *
 * Which anonymous routes exist is pinned by `tests/Security/PublicRoutesInventoryTest`:
 * each one is covered by a pattern below or names its own throttle there (the login
 * form: the firewall's `login_throttling`).
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 10)]
final class PublicPathRateLimitSubscriber
{
    private const string TOKEN = 'token';
    private const string PROBE = 'probe';
    private const string SSO = 'sso';
    private const string QUICK_FIX = 'quick_fix';

    /** Path pattern => limiter, first match wins. */
    public const array PATTERNS = [
        '#^/trust/#' => self::TOKEN,
        '#^/supplier-questionnaire/#' => self::TOKEN,
        '#^/(health|readyz|metrics)$#' => self::PROBE,
        '#^/(sso|saml|oauth)/#' => self::SSO,
        '#^/(de|en)/(sso|saml|oauth)/#' => self::SSO,
        '#^/quick-fix#' => self::QUICK_FIX,
    ];

    /** @var array<string, RateLimiterFactory> */
    private readonly array $limiters;

    public function __construct(
        RateLimiterFactory $publicTokenLimiter,
        RateLimiterFactory $publicProbeLimiter,
        RateLimiterFactory $publicSsoLimiter,
        RateLimiterFactory $publicQuickFixLimiter,
    ) {
        $this->limiters = [
            self::TOKEN => $publicTokenLimiter,
            self::PROBE => $publicProbeLimiter,
            self::SSO => $publicSsoLimiter,
            self::QUICK_FIX => $publicQuickFixLimiter,
        ];
    }

    /** The limiter key for a path, null when the path is not throttled here. */
    public static function limiterFor(string $path): ?string
    {
        foreach (self::PATTERNS as $pattern => $limiter) {
            if (preg_match($pattern, $path) === 1) {
                return $limiter;
            }
        }

        return null;
    }

    public function onKernelRequest(RequestEvent $requestEvent): void
    {
        if (!$requestEvent->isMainRequest()) {
            return;
        }

        $request = $requestEvent->getRequest();
        $key = self::limiterFor($request->getPathInfo());
        if ($key === null) {
            return;
        }

        $limit = $this->limiters[$key]
            ->create('public-' . $key . '-' . ($request->getClientIp() ?? 'unknown'))
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
