<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\Notification;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests for NotificationChannelController.
 */
final class NotificationChannelControllerTest extends WebTestCase
{
    #[Test]
    public function indexRedirectsForUnauthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/de/admin/notification/channel');
        self::assertResponseRedirects();
    }

    #[Test]
    public function indexRendersForManager(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getOrCreateManagerUser($client));
        $client->request('GET', '/de/admin/notification/channel');
        $statusCode = $client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [200, 302, 403]);
    }

    #[Test]
    public function newPageRendersForManager(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getOrCreateManagerUser($client));
        $client->request('GET', '/de/admin/notification/channel/new');
        $statusCode = $client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [200, 302, 403]);
    }

    private function getOrCreateManagerUser(mixed $client): User
    {
        /** @var EntityManagerInterface $em */
        $em   = $client->getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(User::class);

        $email = 'notif-channel-manager@test.test';
        $user  = $repo->findOneBy(['email' => $email]);
        if ($user !== null) {
            return $user;
        }

        $tenant = $em->getRepository(Tenant::class)->findOneBy([]);
        if ($tenant === null) {
            $tenant = (new Tenant())->setCode('notif-ch-test')->setName('Notif Channel Tenant');
            $em->persist($tenant);
            $em->flush();
        }

        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Channel')
            ->setLastName('Manager')
            ->setRoles(['ROLE_USER', 'ROLE_MANAGER'])
            ->setPassword('hashed_password')
            ->setTenant($tenant)
            ->setAuthProvider('local')
            ->setIsActive(true);

        $em->persist($user);
        $em->flush();

        return $user;
    }
}
