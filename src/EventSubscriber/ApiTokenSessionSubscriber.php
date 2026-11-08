<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\Token\ApiTokenRequest;
use App\Security\Token\RequestScopedSession;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * An API-token request never touches a browser session: right after the
 * kernel's session listener (priority 128) and long before the firewall (8),
 * the request gets a {@see RequestScopedSession}. A session cookie sent along
 * is thereby ignored, and nothing the request does can create a session that a
 * cookie could later reuse without the token (S1, wave 5).
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 127)]
final class ApiTokenSessionSubscriber
{
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest() || !ApiTokenRequest::isTokenRequest($event->getRequest())) {
            return;
        }

        $event->getRequest()->setSession(new RequestScopedSession());
    }
}
