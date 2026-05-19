<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Job\WorkerHealthService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes a per-request cached `worker_status()` Twig function that returns
 * the WorkerHealthService snapshot. Used by `base.html.twig` to render the
 * worker-down warning banner without hammering the DB on every page render.
 *
 * Caching strategy: in-memory per request. We also guard against pre-auth
 * pages (login, setup, error pages) where the banner would be confusing
 * and the user can't act on it anyway.
 */
final class WorkerStatusExtension extends AbstractExtension
{
    /**
     * Routes that must NEVER show the worker-down banner. Pre-auth, setup
     * wizard, error pages. Match by exact route-name or by prefix.
     */
    private const BANNER_BLOCKLIST_PREFIXES = [
        'app_login',
        'app_logout',
        'app_reset_password',
        'app_register',
        'setup_',
        '_preview_error',
        '_wdt',
        '_profiler',
    ];

    private ?array $cached = null;

    public function __construct(
        private readonly WorkerHealthService $workerHealth,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('worker_status', $this->getStatus(...)),
            new TwigFunction('worker_banner_visible', $this->shouldShowBanner(...)),
        ];
    }

    /**
     * Returns the cached health snapshot for this request.
     *
     * @return array<string,mixed>
     */
    public function getStatus(): array
    {
        return $this->cached ??= $this->workerHealth->snapshot();
    }

    /**
     * Decides whether the worker-down banner should render on the current page.
     *
     * Rules:
     *  - Only admins ever see the banner (they're the only ones who can act).
     *  - Pre-auth and setup-wizard pages are excluded.
     *  - Only render when state is RED — YELLOW is informational only,
     *    GREEN/UNKNOWN obviously hide.
     */
    public function shouldShowBanner(): bool
    {
        if (!$this->security->isGranted('ROLE_ADMIN')) {
            return false;
        }

        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return false;
        }

        $route = (string) $request->attributes->get('_route', '');
        foreach (self::BANNER_BLOCKLIST_PREFIXES as $prefix) {
            if (str_starts_with($route, $prefix)) {
                return false;
            }
        }

        // Honor opt-out attribute that a route can set via #[Route(defaults: ['_no_worker_banner' => true])]
        if ($request->attributes->get('_no_worker_banner') === true) {
            return false;
        }

        $snapshot = $this->getStatus();
        return ($snapshot['status'] ?? null) === WorkerHealthService::STATUS_RED;
    }
}
