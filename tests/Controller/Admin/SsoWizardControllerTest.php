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

    /**
     * Returns a dedicated SSO-wizard admin tied to a dedicated tenant.
     *
     * Anti-pattern guard (post-#473 incident): we deliberately do NOT
     * `findAll()` on the User repo and pick the first admin — that pattern
     * is vulnerable to fixture-leaks from other tests (e.g. a SUPER_ADMIN
     * persisted in another test class's setUp() that wasn't cleaned up in
     * tearDown leaked into here, was picked up first, and broke this test
     * because that user's tenant didn't have the SSO module active).
     * Use a stable, test-specific email + tenant-code pair instead.
     */
    private function getOrCreateAdminUser(mixed $client): User
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(User::class);

        $email = 'sso-wizard-test-admin@test.test';
        $existing = $repo->findOneBy(['email' => $email]);
        if ($existing !== null) {
            return $existing;
        }

        $tenant = $this->getOrCreateTenant($em);

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

    /**
     * Returns a dedicated tenant for the SSO-wizard test family.
     * Looked up by deterministic code (NOT findOneBy([])) to avoid picking
     * up a leaked tenant whose module config breaks the wizard's routes.
     */
    private function getOrCreateTenant(EntityManagerInterface $em): Tenant
    {
        $code = 'SWT-FIXED';
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => $code]);
        if ($tenant !== null) {
            return $tenant;
        }
        $tenant = new Tenant();
        $tenant->setName('SsoWizardTest');
        $tenant->setCode($code);
        $em->persist($tenant);
        $em->flush();
        return $tenant;
    }
}
