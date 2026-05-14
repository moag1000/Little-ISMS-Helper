<?php

declare(strict_types=1);

namespace App\Tests\Controller\Notification;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests for InAppNotificationCenterController.
 *
 * Tests that:
 * - Unauthenticated access is redirected.
 * - Bell endpoint returns JSON for authenticated users.
 * - Notification center page renders (or redirects if module inactive).
 */
final class InAppNotificationCenterControllerTest extends WebTestCase
{
    /**
     * Regression guard for E2E round-2 HIGH: /de/notifications/center returned 404.
     * A permanent redirect alias was added. For unauthenticated users the security
     * firewall fires first (302 to login); for authenticated users the alias returns
     * 301 to /de/notifications. Either way the route is FOUND (not 404).
     */
    #[Test]
    public function centerAliasRouteIsFoundNotReturning404(): void
    {
        $client = static::createClient();
        $client->request('GET', '/de/notifications/center');
        // 302 = security redirect to login (unauthenticated); both are acceptable —
        // the critical assertion is that the route EXISTS (no 404/500).
        $statusCode = $client->getResponse()->getStatusCode();
        self::assertNotSame(404, $statusCode, '/de/notifications/center must not return 404 — route alias is missing');
        self::assertNotSame(500, $statusCode, '/de/notifications/center must not return 500 — route alias is broken');
        self::assertContains($statusCode, [301, 302]);
    }

    #[Test]
    public function centerAliasRouteRedirectsToCanonicalCenterWhenAuthenticated(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getOrCreateUser($client));
        $client->followRedirects(false);
        $client->request('GET', '/de/notifications/center');
        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('/de/notifications');
    }

    #[Test]
    public function centerRedirectsForUnauthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/de/notifications');
        self::assertResponseRedirects();
    }

    #[Test]
    public function bellEndpointRedirectsForUnauthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/de/notifications/bell');
        self::assertResponseRedirects();
    }

    #[Test]
    public function bellEndpointReturnsJsonForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $user   = $this->getOrCreateUser($client);
        $client->loginUser($user);
        $client->request('GET', '/de/notifications/bell', [], [], [
            'HTTP_ACCEPT'           => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        $response = $client->getResponse();
        // Either JSON 200 (module active) or redirect (module inactive)
        $statusCode = $response->getStatusCode();
        if ($statusCode === 200) {
            self::assertJson((string) $response->getContent());
            $data = json_decode((string) $response->getContent(), true);
            self::assertArrayHasKey('count', $data);
            self::assertArrayHasKey('items', $data);
        } else {
            self::assertContains($statusCode, [302]);
        }
    }

    #[Test]
    public function centerRendersOrRedirectsForUser(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getOrCreateUser($client));
        $client->request('GET', '/de/notifications');
        $statusCode = $client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [200, 302, 403]);
    }

    private function getOrCreateUser(mixed $client): User
    {
        /** @var EntityManagerInterface $em */
        $em   = $client->getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(User::class);

        $email = 'notif-center-user@test.test';
        $user  = $repo->findOneBy(['email' => $email]);
        if ($user !== null) {
            return $user;
        }

        $tenant = $em->getRepository(Tenant::class)->findOneBy([]);
        if ($tenant === null) {
            $tenant = (new Tenant())->setCode('notif-center-test')->setName('Notif Center Tenant');
            $em->persist($tenant);
            $em->flush();
        }

        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Center')
            ->setLastName('User')
            ->setRoles(['ROLE_USER'])
            ->setPassword('hashed_password')
            ->setTenant($tenant)
            ->setAuthProvider('local')
            ->setIsActive(true);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
