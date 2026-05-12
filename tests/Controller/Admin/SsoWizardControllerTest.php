<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
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
        $client->loginUser($this->getOrCreateAdminUser($client));
        $client->request('GET', '/de/admin/sso/wizard/step1');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    #[Test]
    public function step2RendersForAdmin(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getOrCreateAdminUser($client));
        $client->getContainer()->get('session.factory')?->createSession();
        $client->request('GET', '/de/admin/sso/wizard/step2');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function step3RendersForAdmin(): void
    {
        $client = static::createClient();
        $client->loginUser($this->getOrCreateAdminUser($client));
        $client->request('GET', '/de/admin/sso/wizard/step3');
        self::assertResponseIsSuccessful();
    }

    private function getOrCreateAdminUser(mixed $client): User
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(User::class);

        // Try to find an existing admin in the test DB
        $users = $repo->findAll();
        foreach ($users as $u) {
            if (in_array('ROLE_ADMIN', $u->getRoles(), true) || in_array('ROLE_SUPER_ADMIN', $u->getRoles(), true)) {
                return $u;
            }
        }

        // No fixture users — create a minimal one for this test run
        $email = 'sso-wizard-test-admin@test.test';
        $existing = $repo->findOneBy(['email' => $email]);
        if ($existing !== null) {
            return $existing;
        }

        $tenant = $em->getRepository(Tenant::class)->findOneBy([]) ?? $this->createTenant($em);

        $user = new User();
        $user->setEmail($email);
        $user->setPassword('$2y$13$fake_hashed_password_sso_wizard_00');
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
        $tenant->setName('SsoWizardTest');
        $tenant->setCode('SWT' . substr(uniqid(), -5));
        $em->persist($tenant);
        $em->flush();
        return $tenant;
    }
}
