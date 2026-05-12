<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Sso\OidcDiscoveryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests for the SSO Discovery API endpoint.
 *
 * Validates request handling, auth guard, and response format.
 */
final class SsoDiscoveryApiControllerTest extends WebTestCase
{
    #[Test]
    public function endpointRequiresAdminAuthentication(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/de/api/sso/validate-discovery',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['discoveryUrl' => 'https://example.com'])
        );
        self::assertResponseStatusCodeSame(302); // redirects to login
    }

    #[Test]
    public function returnsErrorForEmptyDiscoveryUrl(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getOrCreateAdminUser($client));
        $client->request(
            'POST',
            '/de/api/sso/validate-discovery',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['discoveryUrl' => ''])
        );
        self::assertResponseStatusCodeSame(422);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($data['ok']);
    }

    #[Test]
    public function returnsErrorForNonHttpsUrl(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getOrCreateAdminUser($client));
        $client->request(
            'POST',
            '/de/api/sso/validate-discovery',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['discoveryUrl' => 'http://insecure.example.com'])
        );
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
        $client->request(
            'POST',
            '/de/api/sso/validate-discovery',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['discoveryUrl' => 'https://fail.example.com/.well-known/openid-configuration'])
        );
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($data['ok']);
        self::assertStringContainsString('Connection refused', $data['error']);
    }

    private function getOrCreateAdminUser(mixed $client): User
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(User::class);

        // Try existing admin first
        $users = $repo->findAll();
        foreach ($users as $u) {
            if (in_array('ROLE_ADMIN', $u->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $u->getRoles(), true)) {
                return $u;
            }
        }

        // No fixture users — create a minimal one for this test run
        $email = 'sso-discovery-test-admin@test.test';
        $existing = $repo->findOneBy(['email' => $email]);
        if ($existing !== null) {
            return $existing;
        }

        $tenant = $em->getRepository(Tenant::class)->findOneBy([]) ?? $this->createTenant($em);

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

    private function createTenant(EntityManagerInterface $em): Tenant
    {
        $tenant = new Tenant();
        $tenant->setName('SsoDiscoveryTest');
        $tenant->setCode('SDT' . substr(uniqid(), -5));
        $em->persist($tenant);
        $em->flush();
        return $tenant;
    }
}
