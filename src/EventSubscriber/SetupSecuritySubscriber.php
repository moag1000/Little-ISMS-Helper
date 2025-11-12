<?php

namespace App\EventSubscriber;

use App\Security\SetupAccessChecker;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Security;

/**
 * Event subscriber to enforce access control for setup routes.
 *
 * Security Policy:
 * - Before setup completion: /setup is PUBLIC
 * - After setup completion: /setup requires ROLE_ADMIN
 *
 * This prevents unauthorized access to the setup wizard after initial configuration.
 */
class SetupSecuritySubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SetupAccessChecker $setupChecker,
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 9], // Before firewall (priority 8)
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Only handle master requests
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        // Only check setup routes
        if (!str_starts_with($path, '/setup')) {
            return;
        }

        // Allow access if setup is not complete (public access)
        if (!$this->setupChecker->isSetupComplete()) {
            return;
        }

        // Setup is complete - check authentication and authorization
        $user = $this->security->getUser();
        $isAuthenticated = $user !== null;
        $roles = $isAuthenticated ? $user->getRoles() : [];

        // Check if user can access setup
        if (!$this->setupChecker->canAccessSetup($isAuthenticated, $roles)) {
            // User not authenticated or not admin - redirect to login
            if (!$isAuthenticated) {
                $loginUrl = $this->urlGenerator->generate('app_login');
                $event->setResponse(new RedirectResponse($loginUrl));
            } else {
                // User authenticated but not admin - show access denied
                throw new \Symfony\Component\Security\Core\Exception\AccessDeniedException(
                    'Setup wizard is only accessible to administrators after initial setup completion.'
                );
            }
        }
    }
}
