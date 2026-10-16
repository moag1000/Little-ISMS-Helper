<?php

declare(strict_types=1);

namespace App\Tests\Controller\Trait;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * Seeds a session-stateful CSRF token for WebTestCase requests.
 *
 * The token manager stores tokens under `_csrf/<id>` in the session, so the
 * session must exist first: call after loginUser(), the helper warms the SAME
 * session via a GET, writes the token, and saves+closes the session so the
 * next request sees it (a late set() without save() stays in memory only).
 */
trait SessionCsrfTokenTrait
{
    protected function seedSessionCsrfToken(KernelBrowser $client, string $tokenId, string $warmUpUrl = '/en/dashboard'): string
    {
        $client->request('GET', $warmUpUrl);
        $session = $client->getRequest()->getSession();

        $token = (new UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/' . $tokenId, $token);
        $session->save();

        return $token;
    }
}
