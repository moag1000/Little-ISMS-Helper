<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional smoke tests for the 3-step SSO wizard.
 *
 * Tests that each step renders and redirects correctly for an admin user.
 * Full form processing is covered in unit tests for the form types.
 */
final class SsoWizardControllerTest extends WebTestCase
{
    #[Test]
    public function step1RequiresAdminRole(): void
    {
        $client = static::createClient();
        $client->request('GET', '/de/admin/sso/wizard/step1');
        self::assertResponseRedirects();
    }

    #[Test]
    public function step1RendersForAdmin(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getAdminUser($client));
        $client->request('GET', '/de/admin/sso/wizard/step1');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    #[Test]
    public function step2RendersForAdmin(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getAdminUser($client));
        $client->getContainer()->get('session.factory')?->createSession();
        $client->request('GET', '/de/admin/sso/wizard/step2');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function step3RendersForAdmin(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getAdminUser($client));
        $client->request('GET', '/de/admin/sso/wizard/step3');
        self::assertResponseIsSuccessful();
    }

    private function getAdminUser(mixed $client): \App\Entity\User
    {
        $em = $client->getContainer()->get('doctrine.orm.entity_manager');
        /** @var \App\Repository\UserRepository $repo */
        $repo = $em->getRepository(\App\Entity\User::class);
        $user = $repo->findOneBy(['roles' => null]) ?? $repo->findAll()[0] ?? null;

        // Fall back: find any user with ROLE_ADMIN
        $users = $repo->findAll();
        foreach ($users as $u) {
            if (in_array('ROLE_ADMIN', $u->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $u->getRoles(), true)) {
                return $u;
            }
        }

        // Last resort: use first user and grant role in-memory
        if (!empty($users)) {
            $users[0]->setRoles(['ROLE_ADMIN']);
            return $users[0];
        }

        throw new \RuntimeException('No users in test database — run fixtures first.');
    }
}
