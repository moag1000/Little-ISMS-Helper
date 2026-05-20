<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Notification\NotificationTemplate;
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
    public function testRepairOrphansWithValidCsrfRendersProgressPage(): void
    {
        $this->client->loginUser($this->testUser);
        $token = $this->generateCsrfToken('quick_fix_repair_orphans');

        $this->client->request('POST', '/quick-fix/repair/orphans', [
            '_token' => $token,
        ]);

        // Turbo PRG fix: POST now returns 303 → /quick-fix/jobs/{id}/progress.
        // Follow the redirect and verify the progress page renders the label.
        $this->assertResponseRedirects();
        $crawler = $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Repair Orphans');
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
    public function testRepairTenantMismatchesWithValidCsrfRendersProgressPage(): void
    {
        $this->client->loginUser($this->testUser);
        $token = $this->generateCsrfToken('quick_fix_repair_mismatches');

        $this->client->request('POST', '/quick-fix/repair/tenant-mismatches', [
            '_token' => $token,
        ]);

        // Turbo PRG fix: POST now redirects (303) → progress page.
        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Repair Tenant Mismatches');
    }

    // =========================================================================
    // POST /quick-fix/repair/duplicates/{entityType}
    // =========================================================================

    #[Test]
    public function testRepairDuplicatesKnownTypeRendersProgressPage(): void
    {
        $this->client->loginUser($this->testUser);
        $token = $this->generateCsrfToken('quick_fix_repair_duplicates');

        $this->client->request('POST', '/quick-fix/repair/duplicates/assets', [
            '_token' => $token,
        ]);

        // Turbo PRG fix: POST now redirects (303) → progress page.
        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Repair Duplicates');
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
    public function testRepairAllChainsAllOperationsAndRendersProgressPage(): void
    {
        $this->client->loginUser($this->testUser);
        $token = $this->generateCsrfToken('quick_fix_repair_all');

        $this->client->request('POST', '/quick-fix/repair/all', [
            '_token' => $token,
        ]);

        // Turbo PRG fix: POST now redirects (303) → progress page.
        // The job still runs inline because the in-request runner executes
        // synchronously when fastcgi_finish_request is unavailable (CLI),
        // so the regression assertions in
        // testRepairAllDoesNotMutateGlobalNotificationTemplates still observe
        // the chained orphan + mismatch + dup steps.
        $this->assertResponseRedirects();
        $this->client->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('body', 'Repair All');
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

    // =========================================================================
    // Regression: global-catalogue NotificationTemplate exemption
    // Verifies that repair-all does NOT assign a tenant_id to seeded global
    // NotificationTemplate rows (tenant_id=NULL by design). Before the fix,
    // this triggered UniqueConstraintViolationException on the second row
    // because (template_key + NULL) and (template_key + 1) share the same
    // unique key uniq_template_key_tenant.
    // =========================================================================

    #[Test]
    public function testRepairAllDoesNotMutateGlobalNotificationTemplates(): void
    {
        $this->client->loginUser($this->testUser);

        // Seed two global NotificationTemplates (tenant_id=NULL) with different keys.
        // Before the fix, repair-all would assign tenant_id=<first-tenant> to both,
        // causing a UniqueConstraintViolationException on the second flush.
        $templateA = new NotificationTemplate();
        $templateA->setTemplateKey('test.global.template.a');
        $templateA->setName('Global Template A');
        $templateA->setDefaultEventType('test.event');
        $templateA->setCategory(NotificationTemplate::CATEGORY_INCIDENT);
        // tenant intentionally null (global)

        $templateB = new NotificationTemplate();
        $templateB->setTemplateKey('test.global.template.b');
        $templateB->setName('Global Template B');
        $templateB->setDefaultEventType('test.event');
        $templateB->setCategory(NotificationTemplate::CATEGORY_INCIDENT);
        // tenant intentionally null (global)

        $this->entityManager->persist($templateA);
        $this->entityManager->persist($templateB);
        $this->entityManager->flush();

        $templateAId = $templateA->getId();
        $templateBId = $templateB->getId();

        try {
            $token = $this->generateCsrfToken('quick_fix_repair_all');

            // This must NOT throw a UniqueConstraintViolationException.
            // The sync transport in test env executes QuickFixRepairAllJob
            // inline so the assertions below observe its effects on the
            // seeded NotificationTemplate rows.
            $this->client->request('POST', '/quick-fix/repair/all', [
                '_token' => $token,
            ]);

            // Turbo PRG fix: POST now redirects (303) → progress page.
            $this->assertResponseRedirects();

            // Reload and verify tenant_id is still NULL on both templates.
            $this->entityManager->clear();

            $reloadedA = $this->entityManager->find(NotificationTemplate::class, $templateAId);
            $reloadedB = $this->entityManager->find(NotificationTemplate::class, $templateBId);

            $this->assertNotNull($reloadedA, 'Template A must still exist after repair-all.');
            $this->assertNotNull($reloadedB, 'Template B must still exist after repair-all.');

            $this->assertNull(
                $reloadedA->getTenant(),
                'repair-all must not assign a tenant_id to global NotificationTemplate A.',
            );
            $this->assertNull(
                $reloadedB->getTenant(),
                'repair-all must not assign a tenant_id to global NotificationTemplate B.',
            );
        } finally {
            // Clean up seeded templates regardless of test outcome.
            foreach ([$templateAId, $templateBId] as $id) {
                $tpl = $this->entityManager->find(NotificationTemplate::class, $id);
                if ($tpl !== null) {
                    $this->entityManager->remove($tpl);
                }
            }
            $this->entityManager->flush();
        }
    }
}
