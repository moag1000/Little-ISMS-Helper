<?php

namespace App\EventSubscriber;

use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;
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
 * Security Event Subscriber
 *
 * Automatically logs security-relevant events for compliance and forensic analysis.
 * Implements OWASP A9: Security Logging and Monitoring Failures prevention.
 *
 * Features:
 * - Login success/failure tracking
 * - Logout event logging
 * - Access denied event tracking
 * - Authentication exception monitoring
 * - Automatic integration with SecurityEventLogger service
 *
 * Events Monitored:
 * - LoginSuccessEvent: Successful authentication
 * - LoginFailureEvent: Failed login attempts
 * - LogoutEvent: User logout
 * - AccessDeniedException: Authorization failures
 * - AuthenticationException: Authentication errors
 *
 * Security Benefits:
 * - Brute force attack detection
 * - Unauthorized access monitoring
 * - Audit trail for compliance (ISO 27001, GDPR)
 */
class SecurityEventSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly SecurityEventLogger $securityEventLogger
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
    public function onLoginSuccess(LoginSuccessEvent $loginSuccessEvent): void
    {
        $user = $loginSuccessEvent->getUser();
        $this->securityEventLogger->logLoginSuccess($user);
    }

    /**
     * Security: Log failed login attempt
     */
    public function onLoginFailure(LoginFailureEvent $loginFailureEvent): void
    {
        $passport = $loginFailureEvent->getPassport();

        // Try to get username from passport, then from request, fallback to 'unknown'
        $username = 'unknown';
        if ($passport instanceof Passport) {
            $username = $passport->getUser()->getUserIdentifier() ?? 'unknown';
        }

        // If still unknown, try to get from request
        if ($username === 'unknown') {
            $request = $loginFailureEvent->getRequest();
            $username = $request->request->get('_username')
                ?? $request->request->get('email')
                ?? $request->request->get('username')
                ?? 'unknown';
        }

        $authenticationException = $loginFailureEvent->getException();
        $this->securityEventLogger->logLoginFailure($username, $authenticationException->getMessage());
    }

    /**
     * Security: Log logout
     */
    public function onLogout(LogoutEvent $logoutEvent): void
    {
        $token = $logoutEvent->getToken();
        if ($token instanceof TokenInterface && $token->getUser() instanceof UserInterface) {
            $this->securityEventLogger->logLogout($token->getUser());
        }
    }

    /**
     * Security: Log access denied exceptions
     */
    public function onKernelException(ExceptionEvent $exceptionEvent): void
    {
        $throwable = $exceptionEvent->getThrowable();

        // Log access denied
        if ($throwable instanceof AccessDeniedException) {
            $request = $exceptionEvent->getRequest();
            $user = $request->attributes->get('_security_token')?->getUser();

            $this->securityEventLogger->logAccessDenied(
                $request->getPathInfo(),
                $request->getMethod(),
                $user
            );
        }

        // Log authentication failures
        if ($throwable instanceof AuthenticationException) {
            $this->securityEventLogger->logSuspiciousActivity(
                'Authentication exception: ' . $throwable->getMessage()
            );
        }
    }
}
