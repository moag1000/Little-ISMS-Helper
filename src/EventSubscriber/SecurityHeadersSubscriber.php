<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Security: Add security headers to all responses
 *
 * This subscriber adds important security headers to prevent common attacks.
 * Headers are only added in production to avoid interfering with development.
 */
class SecurityHeadersSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly string $environment
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => ['onKernelResponse', -10],
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        // Only apply security headers in production
        if ($this->environment !== 'prod') {
            return;
        }

        $response = $event->getResponse();

        // Security: X-Content-Type-Options
        // Prevents MIME-type sniffing attacks
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Security: X-Frame-Options
        // Prevents clickjacking attacks
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Security: Referrer-Policy
        // Controls how much referrer information is shared
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Security: X-XSS-Protection (legacy browsers)
        // Modern browsers use CSP instead
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Security: Content-Security-Policy
        // Restricts resource loading to prevent XSS attacks
        // Using a permissive policy to avoid breaking functionality
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' 'unsafe-eval'", // Allow inline scripts for Stimulus
            "style-src 'self' 'unsafe-inline'", // Allow inline styles
            "img-src 'self' data: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "base-uri 'self'",
            "form-action 'self'",
        ]);
        $response->headers->set('Content-Security-Policy', $csp);

        // Security: Permissions-Policy
        // Restricts browser features
        $permissionsPolicy = implode(', ', [
            'geolocation=()',
            'microphone=()',
            'camera=()',
            'payment=()',
            'usb=()',
        ]);
        $response->headers->set('Permissions-Policy', $permissionsPolicy);

        // Security: Strict-Transport-Security (HSTS)
        // Forces HTTPS connections (only if request is HTTPS)
        if ($event->getRequest()->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
    }
}
