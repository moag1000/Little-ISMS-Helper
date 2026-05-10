<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * Functional tests for QuickFixController — operator emergency UI.
 *
 * Tests cover:
 * - /quick-fix GET (index renders, includes integrity section)
 * - /quick-fix/repair/orphans (POST) — assigns orphans, guarded
 * - /quick-fix/repair/tenant-mismatches (POST) — fixes mismatches
 * - /quick-fix/repair/duplicates/{type} (POST) — merges duplicates
 * - /quick-fix/repair/all (POST) — all-in-one convenience route
 *
 * CSRF is injected via session (same pattern as RiskControllerTest).
 */
class QuickFixControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->testUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception) {
                // ignore
            }
        }

        if ($this->testTenant) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Exception) {
                // ignore
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception) {
            // ignore
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('qf_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('QuickFix Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('qf_tenant_' . substr($uniqueId, 0, 16));
        $this->entityManager->persist($this->testTenant);

        $this->testUser = new User();
        $this->testUser->setEmail('qf_user_' . $uniqueId . '@example.com');
        $this->testUser->setFirstName('QF');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_SUPER_ADMIN']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        $this->entityManager->flush();
    }

    /**
     * Generate a CSRF token for the given token ID and inject it into the
     * test session. Mirrors the pattern used in RiskControllerTest.
     */
    private function generateCsrfToken(string $tokenId): string
    {
        // Make sure there is an active session in the browser context.
        $this->client->request('GET', '/quick-fix');

        $session = $this->client->getRequest()->getSession();
        $generator = new UriSafeTokenGenerator();
        $tokenValue = $generator->generateToken();
        // SessionTokenStorage stores CSRF values under "_csrf/<id>".
        $session->set('_csrf/' . $tokenId, $tokenValue);
        $session->save();

        return $tokenValue;
    }

    // =========================================================================
    // GET /quick-fix — index
    // =========================================================================

    #[Test]
    public function testIndexIsAccessibleWithoutLogin(): void
    {
        // QuickFix is public by design (fallback for schema errors).
        $this->client->request('GET', '/quick-fix');
        // 200 or a redirect-back to /quick-fix are both acceptable.
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_OK, Response::HTTP_FOUND]);
    }

    #[Test]
    public function testIndexContainsDataIntegritySectionWhenLoggedIn(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/quick-fix');

        $this->assertResponseIsSuccessful();
        // The section heading key is rendered as text from quick_fix.repair.section.title
        $this->assertSelectorExists('main');
        $content = $this->client->getResponse()->getContent();
        $this->assertIsString($content);
        // Template variable rendered — section is always present (even 0 counts)
        $this->assertStringContainsString('repair-row', $content);
    }

    // =========================================================================
    // POST /quick-fix/repair/orphans
    // =========================================================================

    #[Test]
    public function testRepairOrphansWithValidCsrfRedirectsToIndex(): void
    {
        $this->client->loginUser($this->testUser);
        $token = $this->generateCsrfToken('quick_fix_repair_orphans');

        $this->client->request('POST', '/quick-fix/repair/orphans', [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects('/quick-fix?fixed=1');
    }

    #[Test]
    public function testRepairOrphansWithInvalidCsrfReturns419OrRedirect(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('POST', '/quick-fix/repair/orphans', [
            '_token' => 'invalid-token',
        ]);

        // Symfony IsCsrfTokenValid attribute returns 419 or redirect on invalid token
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_FOUND, 419]);
    }

    // =========================================================================
    // POST /quick-fix/repair/tenant-mismatches
    // =========================================================================

    #[Test]
    public function testRepairTenantMismatchesWithValidCsrfRedirectsToIndex(): void
    {
        $this->client->loginUser($this->testUser);
        $token = $this->generateCsrfToken('quick_fix_repair_mismatches');

        $this->client->request('POST', '/quick-fix/repair/tenant-mismatches', [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects('/quick-fix?fixed=1');
    }

    // =========================================================================
    // POST /quick-fix/repair/duplicates/{entityType}
    // =========================================================================

    #[Test]
    public function testRepairDuplicatesKnownTypeRedirectsToIndex(): void
    {
        $this->client->loginUser($this->testUser);
        $token = $this->generateCsrfToken('quick_fix_repair_duplicates');

        $this->client->request('POST', '/quick-fix/repair/duplicates/assets', [
            '_token' => $token,
        ]);

        $this->assertResponseRedirects('/quick-fix?fixed=1');
    }

    #[Test]
    public function testRepairDuplicatesUnknownTypeRedirectsWithError(): void
    {
        $this->client->loginUser($this->testUser);
        $token = $this->generateCsrfToken('quick_fix_repair_duplicates');

        $this->client->request('POST', '/quick-fix/repair/duplicates/unknown_type', [
            '_token' => $token,
        ]);

        // Redirects back to quick-fix index (error flash added)
        $this->assertResponseRedirects('/quick-fix');
    }

    // =========================================================================
    // POST /quick-fix/repair/all
    // =========================================================================

    #[Test]
    public function testRepairAllChainsAllOperationsAndRedirects(): void
    {
        $this->client->loginUser($this->testUser);
        $token = $this->generateCsrfToken('quick_fix_repair_all');

        $this->client->request('POST', '/quick-fix/repair/all', [
            '_token' => $token,
        ]);

        // Must redirect to quick-fix with fixed=1 after running all repairs
        $this->assertResponseRedirects('/quick-fix?fixed=1');
    }

    #[Test]
    public function testRepairAllWithInvalidCsrfReturnsErrorStatus(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('POST', '/quick-fix/repair/all', [
            '_token' => 'bad-token',
        ]);

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [Response::HTTP_FOUND, 419]);
    }
}
