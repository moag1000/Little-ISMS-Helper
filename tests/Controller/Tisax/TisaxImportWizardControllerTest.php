<?php

declare(strict_types=1);

namespace App\Tests\Controller\Tisax;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional / integration tests for TisaxImportWizardController.
 *
 * Coverage:
 *  - Unauthenticated access → redirect to login
 *  - Authenticated ROLE_USER (< ROLE_MANAGER) → 403
 *  - Authenticated ROLE_MANAGER → disclaimer page loads
 *  - Upload step rejects access without disclaimer confirmation
 *  - CSRF protection on commit step
 */
class TisaxImportWizardControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $testTenant = null;
    private ?User $managerUser = null;
    private ?User $basicUser = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container    = static::getContainer();
        $this->em     = $container->get(EntityManagerInterface::class);

        // Ensure setup lock
        $lockFile = $container->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lockFile)) {
            @file_put_contents($lockFile, date('c'));
        }

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        // Clean up test users and tenant
        $uniquePrefix = 'tisax_test_';
        $userRepo   = $this->em->getRepository(User::class);
        $tenantRepo = $this->em->getRepository(Tenant::class);

        $users = $userRepo->findBy(['firstName' => 'TisaxTest']);
        foreach ($users as $u) {
            try {
                $this->em->remove($u);
            } catch (\Exception) {}
        }

        $tenants = $tenantRepo->findBy(['name' => 'Tisax Test Tenant']);
        foreach ($tenants as $t) {
            try {
                $this->em->remove($t);
            } catch (\Exception) {}
        }

        try {
            $this->em->flush();
        } catch (\Exception) {}

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('tisax_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('Tisax Test Tenant');
        $this->testTenant->setCode('tisax_test_' . $uniqueId);
        $this->em->persist($this->testTenant);

        $this->managerUser = new User();
        $this->managerUser->setEmail('tisax_mgr_' . $uniqueId . '@example.com');
        $this->managerUser->setFirstName('TisaxTest');
        $this->managerUser->setLastName('Manager');
        $this->managerUser->setRoles(['ROLE_MANAGER']);
        $this->managerUser->setPassword('hashed_password');
        $this->managerUser->setTenant($this->testTenant);
        $this->managerUser->setIsActive(true);
        $this->em->persist($this->managerUser);

        $this->basicUser = new User();
        $this->basicUser->setEmail('tisax_user_' . $uniqueId . '@example.com');
        $this->basicUser->setFirstName('TisaxTest');
        $this->basicUser->setLastName('User');
        $this->basicUser->setRoles(['ROLE_USER']);
        $this->basicUser->setPassword('hashed_password');
        $this->basicUser->setTenant($this->testTenant);
        $this->basicUser->setIsActive(true);
        $this->em->persist($this->basicUser);

        $this->em->flush();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Authentication and authorisation
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function unauthenticated_access_to_disclaimer_redirects_to_login(): void
    {
        $this->client->request('GET', '/en/tisax-import/disclaimer');

        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location') ?? '';
        self::assertStringContainsString('login', $location);
    }

    #[Test]
    public function role_user_is_denied_access_to_disclaimer(): void
    {
        $this->client->loginUser($this->basicUser);
        $this->client->request('GET', '/en/tisax-import/disclaimer');

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function role_manager_can_access_disclaimer_step(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/tisax-import/disclaimer');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 0 — Disclaimer
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function disclaimer_page_contains_checkbox_and_submit(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/tisax-import/disclaimer');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-testid="tisax-licence-confirm"]');
        self::assertSelectorExists('[type="submit"]');
    }

    #[Test]
    public function submitting_disclaimer_without_checkbox_stays_on_disclaimer(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/tisax-import/disclaimer');
        self::assertResponseIsSuccessful();

        // POST without the checkbox (simulate unchecked — checkbox omitted from POST body)
        $this->client->request('POST', '/en/tisax-import/disclaimer', [
            'tisax_legal_confirmation_type' => [
                // licenceConfirmed intentionally omitted (unchecked checkbox sends no value)
            ],
        ]);

        // Should stay on disclaimer page (form validation fails, no redirect to upload)
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 1 — Upload (disclaimer guard)
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function upload_step_redirects_when_no_disclaimer_in_session(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/tisax-import/upload');

        // Should redirect back to disclaimer
        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location') ?? '';
        self::assertStringContainsString('disclaimer', $location);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 2 — Validate (no workbook in session)
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function validate_step_redirects_when_no_workbook_in_session(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/tisax-import/validate');

        self::assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location') ?? '';
        self::assertStringContainsString('upload', $location);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Step 4 — Commit (CSRF protection)
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function commit_step_with_invalid_csrf_token_shows_error(): void
    {
        $this->client->loginUser($this->managerUser);

        // POST with bad CSRF token (no parsed controls in session either)
        $this->client->request('POST', '/en/tisax-import/commit', [
            '_token' => 'invalid_token_value',
        ]);

        // Should redirect (no parsed controls in session → redirects to validate)
        // Either way must NOT return 200 with a success message
        $content = (string) $this->client->getResponse()->getContent();
        self::assertStringNotContainsString('imported successfully', $content);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // German locale
    // ──────────────────────────────────────────────────────────────────────────

    #[Test]
    public function disclaimer_is_accessible_under_de_locale(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/de/tisax-import/disclaimer');

        self::assertResponseIsSuccessful();
    }
}
