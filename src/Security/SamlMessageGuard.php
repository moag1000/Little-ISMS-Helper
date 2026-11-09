<?php

declare(strict_types=1);

namespace App\Security;

use OneLogin\Saml2\Utils;
use Psr\Cache\CacheItemPoolInterface;
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
 *  - {@see consumeRequestId()}: the response's InResponseTo must name such a
 *    request; the id is used up on first sight. Unsolicited (IdP-initiated)
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
    /** Kept when the assertion names no NotOnOrAfter. */
    public const int ASSERTION_FALLBACK_TTL = 8 * 3600;

    public function __construct(
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    public function rememberRequest(string $requestId): void
    {
        if ($requestId === '') {
            return;
        }
        $item = $this->cache->getItem($this->key('req', $requestId));
        $item->set(true);
        $item->expiresAfter(self::REQUEST_TTL);
        $this->cache->save($item);
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
        if (!$this->cache->getItem($key)->isHit()) {
            throw new AuthenticationException('SAML response answers an unknown or already used sign-in request.');
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
