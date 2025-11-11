<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Security Headers Subscriber
 *
 * Automatically adds security-related HTTP headers to all responses to prevent common attacks.
 * Implements multiple OWASP Top 10 mitigations through HTTP security headers.
 *
 * Security Headers:
 * - X-Content-Type-Options: Prevents MIME-type sniffing (OWASP A3: Injection prevention)
 * - X-Frame-Options: Prevents clickjacking attacks (OWASP A1: Broken Access Control)
 * - X-XSS-Protection: Legacy XSS protection for older browsers
 * - Referrer-Policy: Controls referrer information leakage
 * - Content-Security-Policy: Restricts resource loading (OWASP A3: XSS prevention)
 * - Permissions-Policy: Disables unnecessary browser features
 * - Strict-Transport-Security (HSTS): Enforces HTTPS (OWASP A2: Cryptographic Failures)
 *
 * Environment Behavior:
 * - Production: All security headers enabled
 * - Development: Headers disabled to avoid interfering with debugging tools
 *
 * CSP Configuration:
 * - Permissive policy to support Symfony UX Stimulus
 * - Allows inline scripts and styles (unsafe-inline, unsafe-eval)
 * - Future: Consider stricter CSP with nonces for production hardening
 *
 * HSTS Configuration:
 * - max-age: 15768000 seconds (6 months)
 * - Only enabled on HTTPS requests
 * - Note: Consider includeSubDomains after testing all subdomains support HTTPS
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
        // Hardened policy: removed unsafe-eval, kept unsafe-inline for existing inline scripts
        // Future: Migrate to nonce-based CSP for maximum security
        $csp = implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net", // Stimulus + CDN, removed unsafe-eval
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://fonts.googleapis.com", // Bootstrap + Fonts
            "img-src 'self' data: https: blob:", // Allow images from any HTTPS source and data URIs
            "font-src 'self' data: https://fonts.gstatic.com https://cdn.jsdelivr.net", // Local + Google Fonts + Bootstrap Icons CDN
            "connect-src 'self' https://cdn.jsdelivr.net", // API calls + source maps from CDN
            "frame-ancestors 'self'", // Prevent embedding in iframes (clickjacking protection)
            "base-uri 'self'", // Prevent base tag injection
            "form-action 'self'", // Forms can only submit to same origin
            "object-src 'none'", // Block plugins (Flash, Java, etc.)
            "upgrade-insecure-requests", // Automatically upgrade HTTP to HTTPS
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
        // WARNING: This header tells browsers to ONLY use HTTPS for this domain
        // - max-age: How long browsers should remember (in seconds)
        // - includeSubDomains: Also applies to all subdomains (can break dev/staging!)
        // - preload: For inclusion in browser HSTS preload lists (use with extreme caution!)
        if ($event->getRequest()->isSecure()) {
            // Use shorter max-age initially (6 months), increase to 2 years after testing
            // Remove includeSubDomains if you have HTTP-only subdomains (dev, staging, etc.)
            $response->headers->set('Strict-Transport-Security', 'max-age=15768000');
        }
    }
}
