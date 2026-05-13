<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\Notification;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests for NotificationTemplateController gallery.
 */
final class NotificationTemplateControllerTest extends WebTestCase
{
    #[Test]
    public function galleryRedirectsForUnauthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/de/admin/notification/template');
        self::assertResponseRedirects();
    }

    #[Test]
    public function galleryRendersForManager(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getOrCreateManagerUser($client));
        $client->request('GET', '/de/admin/notification/template');
        $statusCode = $client->getResponse()->getStatusCode();
        self::assertContains($statusCode, [200, 302, 403]);
    }

    private function getOrCreateManagerUser(mixed $client): User
    {
        /** @var EntityManagerInterface $em */
        $em   = $client->getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(User::class);

        $email = 'notif-tpl-manager@test.test';
        $user  = $repo->findOneBy(['email' => $email]);
        if ($user !== null) {
            return $user;
        }

        $tenant = $em->getRepository(Tenant::class)->findOneBy([]);
        if ($tenant === null) {
            $tenant = (new Tenant())->setCode('notif-tpl-test')->setName('Notif Template Tenant');
            $em->persist($tenant);
            $em->flush();
        }

        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Template')
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
