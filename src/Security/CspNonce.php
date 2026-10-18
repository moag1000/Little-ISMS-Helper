<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Per-request Content-Security-Policy nonce.
 *
 * SecurityHeadersSubscriber puts it into `script-src 'nonce-…'`; templates put
 * it on every inline <script> via the Twig function `csp_nonce()` and into
 * `<meta name="csp-nonce">` for Turbo.
 *
 * Turbo Drive: a Turbo visit swaps the body of the existing document, whose
 * CSP (and therefore nonce) stays the one of the initial full page load.
 * assets/js/csp-nonce.js sends that nonce as `X-CSP-Nonce` with every Turbo
 * fetch, and the page rendered for such a request reuses it so its inline
 * scripts carry the nonce the document accepts. Reusing a client-sent value is
 * safe here: a cross-origin page cannot attach a custom header to a request
 * (CORS preflight) nor to a navigation, so an attacker can never choose the
 * nonce of a document they get the victim to load.
 */
final class CspNonce implements ResetInterface
{
    public const string REQUEST_ATTRIBUTE = '_csp_nonce';
    public const string TURBO_HEADER = 'X-CSP-Nonce';

    private ?string $fallback = null;

    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function get(): string
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            // CLI rendering (mails, PDFs): no CSP header, any value will do.
            return $this->fallback ??= self::generate();
        }

        $nonce = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if (!is_string($nonce)) {
            $nonce = self::fromTurboRequest($request) ?? self::generate();
            $request->attributes->set(self::REQUEST_ATTRIBUTE, $nonce);
        }

        return $nonce;
    }

    public function reset(): void
    {
        $this->fallback = null;
    }

    private static function fromTurboRequest(Request $request): ?string
    {
        // Only Turbo's own fetches (they carry X-Turbo-Request-Id) may reuse a nonce.
        if (!$request->headers->has('X-Turbo-Request-Id')) {
            return null;
        }
        $nonce = $request->headers->get(self::TURBO_HEADER);

        return is_string($nonce) && preg_match('/^[A-Za-z0-9+\/_-]{22,128}={0,2}$/', $nonce) === 1 ? $nonce : null;
    }

    private static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
    }
}
