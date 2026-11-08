<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Security\ApiTokenAuthenticator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ends a browser session whose user was deactivated while it was open. The
 * {@see \App\Security\UserChecker} refuses every new login of a deactivated
 * user, but a session that already exists is only refreshed from the database
 * on each request, never re-checked — so this runs right after the firewall
 * (priority 8): logout (session invalidated, remember-me cookie cleared), then
 * the login page, or 401 for a JSON caller (S1, wave 5).
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 7)]
final class DeactivatedUserSubscriber
{
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Only an existing browser session can carry a deactivated user past the
        // UserChecker; without one the lazy firewall stays lazy.
        if (!$event->isMainRequest() || !$event->getRequest()->hasPreviousSession()) {
            return;
        }
        $user = $this->security->getUser();
        if (!$user instanceof User || \App\Security\UserChecker::refusal($user) === null) {
            return;
        }

        $request = $event->getRequest();
        $firewall = $this->security->getFirewallConfig($request);
        if ($firewall === null || $firewall->getName() === ApiTokenAuthenticator::FIREWALL) {
            return; // token requests: UserChecker already answered 401
        }

        $this->security->logout(false);

        $event->setResponse(self::expectsJson($request->getAcceptableContentTypes()[0] ?? '', $request->isXmlHttpRequest())
            ? new JsonResponse(['error' => 'Account is disabled.'], Response::HTTP_UNAUTHORIZED)
            : new RedirectResponse($this->urlGenerator->generate('app_login')));
    }

    private static function expectsJson(string $preferred, bool $xhr): bool
    {
        return $xhr || $preferred === 'application/json' || str_ends_with($preferred, '+json');
    }
}
