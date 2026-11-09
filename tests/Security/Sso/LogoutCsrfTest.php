<?php

declare(strict_types=1);

namespace App\Tests\Security\Sso;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * S9: signing out needs the CSRF token of the logout link (`logout_path()`).
 * Before, any page could embed `<img src="/logout">` and end the session of
 * whoever opened it — also mid-way through an SSO or MFA step.
 */
final class LogoutCsrfTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $id = uniqid('lo_', true);
        $tenant = (new Tenant())->setName('Logout Org ' . $id)->setCode('lo_' . str_replace('.', '', $id));
        $em->persist($tenant);
        $user = (new User())->setEmail($id . '@example.test')->setFirstName('Lou')->setLastName('Out')
            ->setRoles(['ROLE_USER'])->setPassword('hashed')->setTenant($tenant)->setIsActive(true);
        $em->persist($user);
        $em->flush();
        $this->client->loginUser($user);
    }

    #[Test]
    public function aBareLogoutLinkDoesNotSignOut(): void
    {
        $this->client->request('GET', '/en/logout');
        self::assertNotSame(404, $this->client->getResponse()->getStatusCode());
        self::assertFalse($this->redirectsToLogin(), 'GET /logout without token signed the person out');

        $this->client->request('GET', '/en/dashboard');
        self::assertFalse($this->redirectsToLogin(), 'the session ended without a valid logout token');
    }

    #[Test]
    public function theLogoutLinkWithItsTokenSignsOut(): void
    {
        $token = 'logout-token-' . bin2hex(random_bytes(8));
        $this->storeInSession('_csrf/logout_link', $token);

        $this->client->request('GET', '/en/logout?_csrf_token=' . $token);
        self::assertTrue($this->redirectsToLogin());

        $this->client->request('GET', '/en/dashboard');
        self::assertTrue($this->redirectsToLogin(), 'still signed in after logout');
    }

    private function redirectsToLogin(): bool
    {
        $response = $this->client->getResponse();

        return $response->isRedirect() && str_contains((string) $response->headers->get('Location'), '/login');
    }

    private function storeInSession(string $key, string $value): void
    {
        $cookie = $this->client->getCookieJar()->get('MOCKSESSID') ?? $this->sessionCookie();
        self::assertNotNull($cookie, 'no session cookie after loginUser()');
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->setId($cookie->getValue());
        $session->start();
        $session->set($key, $value);
        $session->save();
    }

    private function sessionCookie(): ?\Symfony\Component\BrowserKit\Cookie
    {
        foreach ($this->client->getCookieJar()->all() as $cookie) {
            if (!str_starts_with($cookie->getName(), 'REMEMBER')) {
                return $cookie;
            }
        }

        return null;
    }
}
