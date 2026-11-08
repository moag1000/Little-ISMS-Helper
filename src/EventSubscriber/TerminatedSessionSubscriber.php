<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * "Sitzung beenden" in the admin session list (and "all sessions" on
 * deactivation) only marks the `user_sessions` row inactive; the browser
 * kept its cookie and stayed signed in. This listener closes that gap: on
 * every main request of a signed-in browser session whose row is no longer
 * active, the session is invalidated and the person is sent to the login.
 *
 * Only an ending somebody decided counts (`forced` by an administrator or
 * deactivation, `limit_exceeded` by the concurrent-session limit). A row the
 * idle cleanup marked `timeout` is not a decision — PHP's own session
 * lifetime handles idleness. While the row is active its last activity is
 * refreshed (at most every five minutes), so the cleanup and the admin list
 * see real activity.
 *
 * Runs after the firewall (priority 8), before MFA and setup checks. A
 * session without a row (table missing, created before session tracking)
 * is left alone.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 6)]
final readonly class TerminatedSessionSubscriber
{
    /** End reasons that sign the browser out. */
    public const array ENDED_BY_DECISION = ['forced', 'limit_exceeded'];

    private const int ACTIVITY_REFRESH_SECONDS = 300;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TokenStorageInterface $tokenStorage,
        private UserSessionRepository $sessions,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || !$request->hasPreviousSession()) {
            return;
        }
        if (!$this->tokenStorage->getToken()?->getUser() instanceof User) {
            return;
        }

        $session = $request->getSession();
        try {
            $row = $this->sessions->findBySessionId($session->getId());
        } catch (\Throwable $e) {
            $this->logger->debug('Session row lookup failed', ['error' => $e->getMessage()]);

            return;
        }
        if (!$row instanceof UserSession) {
            return;
        }
        if ($row->isActive()) {
            $this->refreshActivity($row);

            return;
        }
        if (!in_array($row->getEndReason(), self::ENDED_BY_DECISION, true)) {
            return;
        }

        $session->invalidate();
        $this->tokenStorage->setToken(null);

        $wantsJson = $request->getPreferredFormat() === 'json'
            || str_starts_with($request->getPathInfo(), '/api')
            || $request->isXmlHttpRequest();
        $event->setResponse($wantsJson
            ? new JsonResponse(['error' => 'session_ended'], JsonResponse::HTTP_UNAUTHORIZED)
            : new RedirectResponse($this->urls->generate('app_login')));
    }

    private function refreshActivity(UserSession $row): void
    {
        $last = $row->getLastActivityAt();
        if ($last !== null && $last > new \DateTimeImmutable('-' . self::ACTIVITY_REFRESH_SECONDS . ' seconds')) {
            return;
        }
        try {
            $row->updateActivity();
            $this->entityManager->flush();
        } catch (\Throwable $e) {
            $this->logger->debug('Session activity refresh failed', ['error' => $e->getMessage()]);
        }
    }
}
