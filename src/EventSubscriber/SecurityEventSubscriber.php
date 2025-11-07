<?php

namespace App\EventSubscriber;

use App\Service\SecurityEventLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Security: Automatic security event logging subscriber
 *
 * Automatically logs security-relevant events using the SecurityEventLogger
 */
class SecurityEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SecurityEventLogger $securityLogger
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
            KernelEvents::EXCEPTION => 'onKernelException',
        ];
    }

    /**
     * Security: Log successful login
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        $this->securityLogger->logLoginSuccess($user);
    }

    /**
     * Security: Log failed login attempt
     */
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $passport = $event->getPassport();
        $username = $passport->getUser()?->getUserIdentifier() ?? 'unknown';
        $exception = $event->getException();

        $this->securityLogger->logLoginFailure($username, $exception->getMessage());
    }

    /**
     * Security: Log logout
     */
    public function onLogout(LogoutEvent $event): void
    {
        $token = $event->getToken();
        if ($token && $token->getUser()) {
            $this->securityLogger->logLogout($token->getUser());
        }
    }

    /**
     * Security: Log access denied exceptions
     */
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        // Log access denied
        if ($exception instanceof AccessDeniedException) {
            $request = $event->getRequest();
            $user = $request->attributes->get('_security_token')?->getUser();

            $this->securityLogger->logAccessDenied(
                $request->getPathInfo(),
                $request->getMethod(),
                $user
            );
        }

        // Log authentication failures
        if ($exception instanceof AuthenticationException) {
            $this->securityLogger->logSuspiciousActivity(
                'Authentication exception: ' . $exception->getMessage()
            );
        }
    }
}
