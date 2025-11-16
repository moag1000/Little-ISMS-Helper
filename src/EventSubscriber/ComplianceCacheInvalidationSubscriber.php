<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Invalidates compliance navigation cache when frameworks are modified.
 *
 * This subscriber listens for requests to admin compliance routes that modify
 * frameworks and clears the navigation cache to ensure users see the latest data.
 */
class ComplianceCacheInvalidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CacheInterface $cache
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $route = $request->attributes->get('_route');

        // Invalidate cache when frameworks are loaded, deleted, or modified
        $invalidationRoutes = [
            'admin_compliance_load_framework',
            'admin_compliance_delete_framework',
        ];

        if (in_array($route, $invalidationRoutes, true) && $request->isMethod('POST')) {
            $this->cache->delete('compliance_nav_frameworks');
            $this->cache->delete('compliance_nav_quick');
        }
    }
}
