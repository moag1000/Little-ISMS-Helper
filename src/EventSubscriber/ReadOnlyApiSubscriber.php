<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\ApiToken;
use App\Repository\ApiTokenRepository;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Enforces READ-ONLY access for Bearer-API-token requests.
 *
 * When a request carries a valid Bearer token, only safe HTTP methods (GET,
 * HEAD, OPTIONS) are permitted on every path; any write verb is rejected with
 * 405. The one exception is the assistant endpoint POST /mcp, where writing is
 * decided per tool call by {@see \App\Mcp\McpWriteAccess} (organisation
 * switch, "Assistent darf schreiben" on the token, no reviewer role). This is
 * independent of the per-resource voters and of the firewall, which accepts
 * tokens only on /api and /mcp anyway, so the read-only guarantee holds
 * regardless of how individual routes are secured.
 */
final class ReadOnlyApiSubscriber implements EventSubscriberInterface
{
    private const array SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(
        private readonly ApiTokenRepository $tokenRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Before the controller resolves, but the firewall has already run on
        // a higher priority, so the request is authenticated by here.
        return [KernelEvents::REQUEST => ['onRequest', 8]];
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ($request->getPathInfo() === '/mcp') {
            return; // McpWriteAccess decides per tool call
        }

        $auth = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($auth, 'Bearer ')) {
            return; // not a token request — leave session-auth flows untouched
        }

        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return;
        }

        // Only block when the Bearer token is actually a known, valid API token —
        // otherwise let the authenticator return its 401 for bad tokens.
        $plain = trim(substr($auth, 7));
        $token = $plain !== '' ? $this->tokenRepository->findOneByHash(hash('sha256', $plain)) : null;
        if ($token instanceof ApiToken && $token->isValid(new DateTimeImmutable())) {
            $event->setResponse(new JsonResponse(
                ['error' => 'Read-only API token', 'detail' => 'This token only permits safe (GET/HEAD) requests.'],
                Response::HTTP_METHOD_NOT_ALLOWED,
            ));
        }
    }
}
