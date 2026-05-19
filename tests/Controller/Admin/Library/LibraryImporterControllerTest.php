<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin\Library;

use App\Entity\ComplianceFramework;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Smoke tests for LibraryImporterController.
 *
 * Tests: index GET returns 200, import POST redirects/renders,
 * export endpoints return appropriate response codes.
 * Admin access required.
 */
final class LibraryImporterControllerTest extends WebTestCase
{
    #[Test]
    public function indexRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/de/admin/library');

        // Without auth → redirect to login
        self::assertResponseRedirects();
    }

    #[Test]
    public function indexIsAccessibleAsAdmin(): void
    {
        $client = static::createClient();

        // Get admin user from the database
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = static::getContainer()->get('App\Repository\UserRepository');
        $adminUser = $userRepo->findOneBy(['email' => 'admin@example.com']);

        if ($adminUser === null) {
            self::markTestSkipped('No admin user found in test database.');
        }

        $client->loginUser($adminUser);
        $client->request('GET', '/de/admin/library');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Library');
    }

    #[Test]
    public function importPostRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('POST', '/de/admin/library/import');

        self::assertResponseRedirects();
    }

    #[Test]
    public function importPostWithBsiTypeCallsImporter(): void
    {
        $client = static::createClient();

        // Library import is `W global` per Role-Scope spec §3.1 — writes global
        // ComplianceFramework rows shared across all tenants. Restricted to
        // ROLE_SUPER_ADMIN via TenantScopedAdminVoter::ADMIN_GLOBAL_OP since
        // Phase 4b. Prefer a SUPER user fixture; fall back to the legacy admin
        // user (skipped when neither is present so the test stays clean on
        // empty fixtures).
        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = static::getContainer()->get('App\Repository\UserRepository');
        $superUser = $userRepo->findOneBy(['email' => 'superadmin@example.com'])
            ?? $userRepo->findOneBy(['email' => 'admin@example.com']);

        if ($superUser === null) {
            self::markTestSkipped('No admin user found in test database.');
        }

        $client->loginUser($superUser);

        // Bootstrap session + inject CSRF token (required by #[IsCsrfTokenValid] on import)
        $client->request('GET', '/de/admin/library');
        $session = $client->getRequest()->getSession();
        $tokenGenerator = new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator();
        $tokenValue = $tokenGenerator->generateToken();
        $session->set('_csrf/library_import', $tokenValue);
        $session->save();

        $client->request('POST', '/de/admin/library/import?type=bsi', ['_token' => $tokenValue]);

        // Import may succeed or show partial result — both are 200
        self::assertTrue(
            $client->getResponse()->isSuccessful() || $client->getResponse()->isRedirection(),
            sprintf('Expected 2xx or 3xx, got %d', $client->getResponse()->getStatusCode()),
        );
    }

    #[Test]
    public function exportYamlReturns404ForUnknownFramework(): void
    {
        $client = static::createClient();

        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = static::getContainer()->get('App\Repository\UserRepository');
        $adminUser = $userRepo->findOneBy(['email' => 'admin@example.com']);

        if ($adminUser === null) {
            self::markTestSkipped('No admin user found in test database.');
        }

        $client->loginUser($adminUser);
        $client->request('GET', '/de/admin/library/export/999999/yaml');

        self::assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function exportCsvReturns404ForUnknownFramework(): void
    {
        $client = static::createClient();

        /** @var \App\Repository\UserRepository $userRepo */
        $userRepo = static::getContainer()->get('App\Repository\UserRepository');
        $adminUser = $userRepo->findOneBy(['email' => 'admin@example.com']);

        if ($adminUser === null) {
            self::markTestSkipped('No admin user found in test database.');
        }

        $client->loginUser($adminUser);
        $client->request('GET', '/de/admin/library/export/999999/csv');

        self::assertResponseStatusCodeSame(404);
    }
}
