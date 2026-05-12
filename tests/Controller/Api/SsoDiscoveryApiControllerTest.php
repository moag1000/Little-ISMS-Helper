<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\IdentityProvider;
use App\Service\Sso\OidcDiscoveryService;
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
        $client->loginUser($this->getAdminUser($client));
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
        $client->loginUser($this->getAdminUser($client));
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

        $client->loginUser($this->getAdminUser($client));
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

    private function getAdminUser(mixed $client): \App\Entity\User
    {
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        $repo = $em->getRepository(\App\Entity\User::class);
        $users = $repo->findAll();
        foreach ($users as $u) {
            if (in_array('ROLE_ADMIN', $u->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $u->getRoles(), true)) {
                return $u;
            }
        }
        if (!empty($users)) {
            $users[0]->setRoles(['ROLE_ADMIN']);
            return $users[0];
        }
        throw new \RuntimeException('No users in test database.');
    }
}
