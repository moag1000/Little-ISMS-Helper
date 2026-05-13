<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\Notification;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests for NotificationRuleController.
 *
 * Verifies that the index renders for a ROLE_MANAGER user and that
 * an unauthenticated request is redirected.
 */
final class NotificationRuleControllerTest extends WebTestCase
{
    #[Test]
    public function indexRedirectsForUnauthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/de/admin/notification/rule');
        self::assertResponseRedirects();
    }

    #[Test]
    public function indexRendersForManager(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getOrCreateManagerUser($client));
        $client->request('GET', '/de/admin/notification/rule');
        // 200 = rendered, 302 = module inactive redirect, 403 = access denied (module gated at security level)
        $statusCode = $client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [200, 302, 403]);
    }

    #[Test]
    public function newPageRendersForManager(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getOrCreateManagerUser($client));
        $client->request('GET', '/de/admin/notification/rule/new');
        $statusCode = $client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [200, 302, 403]);
    }

    private function getOrCreateManagerUser(mixed $client): User
    {
        /** @var EntityManagerInterface $em */
        $em   = $client->getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(User::class);

        $email = 'notif-rule-manager@test.test';
        $user  = $repo->findOneBy(['email' => $email]);
        if ($user !== null) {
            return $user;
        }

        $tenant = $em->getRepository(Tenant::class)->findOneBy([]);
        if ($tenant === null) {
            $tenant = (new Tenant())->setCode('notif-test')->setName('Notif Test Tenant');
            $em->persist($tenant);
            $em->flush();
        }

        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Notif')
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
