<?php

declare(strict_types=1);

namespace App\Security;

use OneLogin\Saml2\Utils;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

/**
 * Binds every SAML response to a request this installation sent, and accepts
 * each assertion once (S9).
 *
 *  - {@see rememberRequest()}: the AuthnRequest id is stored server-side when
 *    the login starts (10 minutes). The session cannot carry it: the IdP posts
 *    the response cross-site, and the SameSite=Lax session cookie is not sent
 *    with that POST.
 *    Since S14 the request is also bound to the browser that started it: a
 *    random nonce goes into a short-lived `SameSite=None; Secure` cookie
 *    ({@see bindingCookie()}) and next to the id in the cache. A response for
 *    the attacker's own login, posted into a victim's browser (login CSRF),
 *    carries no matching cookie and is refused.
 *  - {@see consumeRequestId()}: the response's InResponseTo must name such a
 *    request of this browser; the id is used up on first sight. Unsolicited (IdP-initiated)
 *    responses are refused. OneLogin then verifies the signed InResponseTo
 *    against it in `processResponse($requestId)`.
 *  - {@see assertFirstUse()}: the assertion id is remembered until its
 *    NotOnOrAfter — a captured response posted again is refused.
 *
 * Uses the application cache; installations with several web servers need a
 * shared cache adapter (`docs/setup/AUTHENTICATION_SETUP.md`, SSO-Härtung).
 */
class SamlMessageGuard
{
    public const int REQUEST_TTL = 600;
    public const string BINDING_COOKIE = 'saml_binding';
    /** Kept when the assertion names no NotOnOrAfter. */
    public const int ASSERTION_FALLBACK_TTL = 8 * 3600;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Remembers the AuthnRequest id; returns the browser binding nonce the
     * caller sets as {@see bindingCookie()} ('' when there is no id).
     */
    public function rememberRequest(string $requestId): string
    {
        if ($requestId === '') {
            return '';
        }
        $nonce = bin2hex(random_bytes(16));
        $item = $this->cache->getItem($this->key('req', $requestId));
        $item->set($nonce);
        $item->expiresAfter(self::REQUEST_TTL);
        $this->cache->save($item);

        return $nonce;
    }

    /**
     * The cookie that binds the sign-in request to this browser. SameSite=None
     * because the IdP posts the response cross-site; HttpOnly.
     */
    public static function bindingCookie(string $nonce, bool $secure = true): Cookie
    {
        return Cookie::create(self::BINDING_COOKIE)
            ->withValue($nonce)
            ->withExpires(time() + self::REQUEST_TTL)
            ->withPath('/')
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite($secure ? Cookie::SAMESITE_NONE : Cookie::SAMESITE_LAX);
    }

    /**
     * The id of the request the posted response answers.
     *
     * @throws AuthenticationException unsolicited, unknown or already answered
     */
    public function consumeRequestId(Request $request): string
    {
        $inResponseTo = $this->inResponseTo((string) $request->request->get('SAMLResponse', ''));
        if ($inResponseTo === null) {
            throw new AuthenticationException('SAML response does not answer a sign-in request of this installation.');
        }
        $key = $this->key('req', $inResponseTo);
        $item = $this->cache->getItem($key);
        if (!$item->isHit()) {
            throw new AuthenticationException('SAML response answers an unknown or already used sign-in request.');
        }
        $nonce = $item->get();
        $cookie = (string) $request->cookies->get(self::BINDING_COOKIE, '');
        if (!is_string($nonce) || $nonce === '' || !hash_equals($nonce, $cookie)) {
            throw new AuthenticationException('SAML response answers a sign-in request started in another browser.');
        }
        $this->cache->deleteItem($key);

        return $inResponseTo;
    }

    /**
     * @throws AuthenticationException the assertion was accepted before
     */
    public function assertFirstUse(?string $assertionId, ?int $notOnOrAfter, ?int $now = null): void
    {
        if ($assertionId === null || $assertionId === '') {
            throw new AuthenticationException('SAML assertion has no id.');
        }
        $item = $this->cache->getItem($this->key('asr', $assertionId));
        if ($item->isHit()) {
            throw new AuthenticationException('SAML assertion was already used.');
        }
        $now ??= time();
        $ttl = $notOnOrAfter !== null && $notOnOrAfter > $now
            ? $notOnOrAfter - $now + 300
            : self::ASSERTION_FALLBACK_TTL;
        $item->set(true);
        $item->expiresAfter($ttl);
        $this->cache->save($item);
    }

    /**
     * InResponseTo of the (base64, HTTP-POST binding) response, read with
     * OneLogin's hardened XML loader (no external entities).
     */
    private function inResponseTo(string $encoded): ?string
    {
        $xml = base64_decode($encoded, true);
        if ($xml === false || $xml === '') {
            return null;
        }
        $dom = Utils::loadXML(new \DOMDocument(), $xml);
        if (!$dom instanceof \DOMDocument || !$dom->documentElement instanceof \DOMElement) {
            return null;
        }
        $id = $dom->documentElement->getAttribute('InResponseTo');

        return $id !== '' ? $id : null;
    }

    private function key(string $kind, string $id): string
    {
        return 'saml_' . $kind . '_' . hash('sha256', $id);
    }
}
