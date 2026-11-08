<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Sso\OidcDiscoveryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests for the SSO Discovery API endpoint.
 *
 * Validates request handling, auth guard, and response format.
 */
#[AllowMockObjectsWithoutExpectations]
final class SsoDiscoveryApiControllerTest extends WebTestCase
{
    #[Test]
    public function endpointRequiresAdminAuthentication(): void
    {
        $client = static::createClient();
        $client->request('POST', '/de/api/sso/validate-discovery', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['discoveryUrl' => 'https://example.com']));
        self::assertContains($client->getResponse()->getStatusCode(), [302, 403], 'Anonymous callers never reach the fetch.');
    }

    #[Test]
    public function aRequestWithoutTheCsrfHeaderIsRefused(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getOrCreateAdminUser($client));
        $client->request('POST', '/de/api/sso/validate-discovery', [], [], ['CONTENT_TYPE' => 'application/json'],
            json_encode(['discoveryUrl' => 'https://93.184.215.14/.well-known/openid-configuration']));
        self::assertResponseStatusCodeSame(403);
    }

    #[Test]
    public function returnsErrorForEmptyDiscoveryUrl(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getOrCreateAdminUser($client));
        $this->postDiscovery($client, json_encode(['discoveryUrl' => '']));
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($data['ok']);
    }

    #[Test]
    public function returnsErrorForNonHttpsUrl(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getOrCreateAdminUser($client));
        $this->postDiscovery($client, json_encode(['discoveryUrl' => 'http://insecure.example.com']));
        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function returnsFalseWhenDiscoveryFails(): void
    {
        $client = static::createClient();

        $mockDiscovery = $this->createMock(OidcDiscoveryService::class);
        $mockDiscovery->method('fetchDiscovery')->willThrowException(new \RuntimeException('Connection refused'));
        $client->getContainer()->set(OidcDiscoveryService::class, $mockDiscovery);

        $client->loginUser($this->getOrCreateAdminUser($client));
        $this->postDiscovery($client, json_encode(['discoveryUrl' => 'https://93.184.215.14/.well-known/openid-configuration']));
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($data['ok']);
        // The transport error (refused / timeout / TLS) never reaches the caller —
        // otherwise the endpoint answers "which port is open" for any host.
        self::assertStringNotContainsString('Connection refused', $data['error']);
    }

    private function postDiscovery(mixed $client, string $body): void
    {
        $client->disableReboot();
        $client->request('GET', '/de/admin/sso/wizard/step1');
        $session = $client->getRequest()->getSession();
        $token = (new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/sso_discovery', $token);
        $session->save();
        $client->request('POST', '/de/api/sso/validate-discovery', [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token], $body);
    }

    /**
     * Returns a dedicated SSO-discovery admin tied to a dedicated tenant.
     *
     * Anti-pattern guard (post-#473 incident): we deliberately do NOT
     * `findAll()` on the User repo and pick the first admin — that pattern
     * is vulnerable to fixture-leaks from other tests (e.g. a SUPER_ADMIN
     * persisted in another test class's setUp() that wasn't cleaned up in
     * tearDown). Use a stable, test-specific email + tenant-code instead.
     */
    private function getOrCreateAdminUser(mixed $client): User
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(User::class);

        $email = 'sso-discovery-test-admin@test.test';
        $existing = $repo->findOneBy(['email' => $email]);
        if ($existing !== null) {
            return $existing;
        }

        $tenant = $this->getOrCreateTenant($em);

        $user = new User();
        $user->setEmail($email);
        $user->setPassword('$2y$13$fake_hashed_password_sso_disco_00');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setTenant($tenant);
        $user->setFirstName('SSO');
        $user->setLastName('Admin');

        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * Returns a dedicated tenant for the SSO-discovery test family.
     * Looked up by deterministic code (NOT findOneBy([])) to avoid picking
     * up a leaked tenant from another test class.
     */
    private function getOrCreateTenant(EntityManagerInterface $em): Tenant
    {
        $code = 'SDT-FIXED';
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => $code]);
        if ($tenant !== null) {
            return $tenant;
        }
        $tenant = new Tenant();
        $tenant->setName('SsoDiscoveryTest');
        $tenant->setCode($code);
        $em->persist($tenant);
        $em->flush();
        return $tenant;
    }
}
