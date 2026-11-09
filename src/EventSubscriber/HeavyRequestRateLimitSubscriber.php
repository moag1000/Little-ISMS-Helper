<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Per-person budget for the expensive pages (S31): exports, PDFs, previews, the
 * analytics data endpoints and recomputations each read whole sets and hold a PHP
 * worker for seconds. Only /api and /mcp had a limit; a few people (or one script
 * with a session) clicking these in parallel could take every worker of the pool.
 *
 * Keyed on the logged-in person (never the IP — a whole office shares one), runs right
 * after the firewall. Routes are matched by name, so a new export is covered without
 * an attribute; {@see self::isHeavy()} is pinned by HeavyRequestRateLimitSubscriberTest.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 7)]
final class HeavyRequestRateLimitSubscriber
{
    /** Route names that do a heavy read; the async-job routes only queue and are cheap. */
    private const string HEAVY = '/(export|_pdf|pdf_|excel|xlsx|_csv|csv_|print|preview|recompute|recalculate|regenerate)|^app_analytics_\w+_data$/';
    /** Job status / result routes and the report designer's single-widget call stay unlimited. */
    private const string LIGHT = '/(_status|_progress|_download|_poll|_cancel)$|^report_builder_widget_data$|^admin_job_/';

    public function __construct(
        private readonly RateLimiterFactory $heavyRequestLimiter,
        private readonly Security $security,
    ) {
    }

    public static function isHeavy(string $routeName): bool
    {
        return preg_match(self::HEAVY, $routeName) === 1 && preg_match(self::LIGHT, $routeName) !== 1;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $route = $request->attributes->get('_route');
        $user = $this->security->getUser();
        if (!is_string($route) || !$user instanceof User || !self::isHeavy($route)) {
            return;
        }

        $limit = $this->heavyRequestLimiter->create('heavy-' . $user->getUserIdentifier())->consume(1);
        if ($limit->isAccepted()) {
            return;
        }

        $event->setResponse(new Response('Too many requests.', Response::HTTP_TOO_MANY_REQUESTS, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
        ]));
    }
}
