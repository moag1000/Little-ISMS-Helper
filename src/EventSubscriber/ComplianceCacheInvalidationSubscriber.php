<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Invalidates compliance navigation cache when frameworks are modified.
 *
 * This subscriber listens for responses from admin compliance routes that modify
 * frameworks and clears the navigation cache to ensure users see the latest data.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, method: 'onKernelResponse', priority: 10)]
final class ComplianceCacheInvalidationSubscriber
{
    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    public function onKernelResponse(ResponseEvent $responseEvent): void
    {
        if (!$responseEvent->isMainRequest()) {
            return;
        }

        $request = $responseEvent->getRequest();
        $response = $responseEvent->getResponse();
        $route = $request->attributes->get('_route');

        // Invalidate cache when frameworks are loaded, deleted, or modified
        $invalidationRoutes = [
            'admin_compliance_load_framework',
            'admin_compliance_delete_framework',
        ];

        // Only invalidate if the operation was successful (2xx status code)
        if (in_array($route, $invalidationRoutes, true)
            && $request->isMethod('POST')
            && $response->isSuccessful()) {
            $this->cache->delete('compliance_nav_frameworks');
            $this->cache->delete('compliance_nav_quick');
        }
    }
}
