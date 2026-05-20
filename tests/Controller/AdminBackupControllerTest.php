<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for AdminBackupController
 *
 * Tests backup and restore functionality including:
 * - Backup index listing
 * - Create backup
 * - Upload backup
 * - Validate backup
 * - Preview restore
 * - Restore backup
 * - Delete backup
 * - Data export
 * - Data import
 */
class AdminBackupControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?Tenant $foreignTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?User $tenantAdminUser = null;

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
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->adminUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->adminUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->tenantAdminUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->tenantAdminUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->testTenant) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        if ($this->foreignTenant) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->foreignTenant->getId());
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Exception $e) {
                // Ignore
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {
            // Ignore
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('test_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('test_tenant_' . $uniqueId);
        $this->entityManager->persist($this->testTenant);

        // Sibling tenant outside testTenant's tree — used by the Role-Scope
        // cross-tenant tests (Phase 3 of the role-scope architecture rollout).
        $this->foreignTenant = new Tenant();
        $this->foreignTenant->setName('Foreign Tenant ' . $uniqueId);
        $this->foreignTenant->setCode('foreign_tenant_' . $uniqueId);
        $this->entityManager->persist($this->foreignTenant);

        $this->testUser = new User();
        $this->testUser->setEmail('testuser_' . $uniqueId . '@example.com');
        $this->testUser->setFirstName('Test');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        $this->adminUser = new User();
        $this->adminUser->setEmail('admin_' . $uniqueId . '@example.com');
        $this->adminUser->setFirstName('Admin');
        $this->adminUser->setLastName('User');
        // SUPER_ADMIN baseline — backup index, export, import etc. still rely
        // on the class-level fence. Phase 3 (role-scope) widens the per-tenant
        // backup endpoints to ROLE_ADMIN (see TenantScopedAdminVoter).
        $this->adminUser->setRoles(['ROLE_SUPER_ADMIN']);
        $this->adminUser->setPassword('hashed_password');
        $this->adminUser->setTenant($this->testTenant);
        $this->adminUser->setIsActive(true);
        $this->entityManager->persist($this->adminUser);

        // Tenant-level admin (NOT SUPER_ADMIN) — exercises the
        // ADMIN_OWN_TENANT voter path on backup endpoints.
        $this->tenantAdminUser = new User();
        $this->tenantAdminUser->setEmail('tenantadmin_' . $uniqueId . '@example.com');
        $this->tenantAdminUser->setFirstName('Tenant');
        $this->tenantAdminUser->setLastName('Admin');
        $this->tenantAdminUser->setRoles(['ROLE_ADMIN']);
        $this->tenantAdminUser->setPassword('hashed_password');
        $this->tenantAdminUser->setTenant($this->testTenant);
        $this->tenantAdminUser->setIsActive(true);
        $this->entityManager->persist($this->tenantAdminUser);

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    /**
     * Execute a same-origin JSON request. The SameOriginCsrfTokenManager
     * accepts any token value when both `Sec-Fetch-Site: same-origin` and a
     * token parameter are present (cf. config/packages/csrf.yaml).
     *
     * Sends `X-Requested-With: XMLHttpRequest` to match the JS fetch()
     * call's headers — AdminBackupController distinguishes the AJAX path
     * (JsonResponse) from the JS-failed form-submit fallback path
     * (server-side redirect to progress page) via this header.
     *
     * @param array<string, mixed> $params Post parameters (will carry `_token=same-origin`)
     */
    private function sameOriginRequest(string $method, string $uri, array $params = [], array $files = []): void
    {
        // 'csrf-token' matches Symfony's default cookie name, which the
        // SameOriginCsrfTokenManager accepts regardless of length (the
        // `$token->getValue() !== $this->cookieName` branch in isTokenValid).
        $params['_token'] = 'csrf-token';
        $this->client->request(
            $method,
            $uri,
            $params,
            $files,
            [
                'HTTP_SEC_FETCH_SITE'   => 'same-origin',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ]
        );
    }

    // ========== BACKUP INDEX TESTS ==========

    #[Test]
    public function testBackupIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/data/backup');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testBackupIndexRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/admin/data/backup');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testBackupIndexDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup');
        $this->assertResponseIsSuccessful();
    }

    // ========== CREATE BACKUP TESTS ==========

    #[Test]
    public function testCreateBackupRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/data/backup/create');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testCreateBackupRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/data/backup/create');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testCreateBackupRequiresPost(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup/create');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function testCreateBackupExecutesForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->sameOriginRequest('POST', '/en/admin/data/backup/create');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    #[Test]
    public function testCreateBackupRedirectsToProgressPageWhenNotXhr(): void
    {
        // Safety net: when the page's inline JS fails (e.g. transient parse
        // error in a third-party module), the browser submits the form via
        // the explicit form `action`. Without `X-Requested-With`, the
        // controller must redirect to the progress page server-side instead
        // of returning a JsonResponse the browser would render as raw text.
        $this->loginAsUser($this->adminUser);
        $this->client->request(
            'POST',
            '/en/admin/data/backup/create',
            ['_token' => 'csrf-token'],
            [],
            ['HTTP_SEC_FETCH_SITE' => 'same-origin'],
        );
        $this->assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        $this->assertNotNull($location);
        // Turbo PRG fix: redirect now goes to the shared progress page at
        // /admin/jobs/{id}/progress (was /admin/data/backup/progress/{id}).
        $this->assertStringContainsString('/admin/jobs/', (string) $location);
        $this->assertStringContainsString('/progress', (string) $location);
    }

    // ========== ROLE-SCOPE PHASE 3 — TENANT-SCOPED BACKUP TESTS ==========

    #[Test]
    public function testCreateBackupSucceedsForRoleAdminOnOwnTenant(): void
    {
        // ROLE_ADMIN (non-SUPER) creating a backup for their own tenant —
        // canonical "happy path" for the new TenantScopedAdminVoter +
        // TenantContext::resolveAdminScope() pairing.
        $this->loginAsUser($this->tenantAdminUser);
        $this->sameOriginRequest('POST', '/en/admin/data/backup/create', [
            'tenant_id' => (string) $this->testTenant->getId(),
        ]);
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    #[Test]
    public function testCreateBackupForbiddenForRoleAdminOnForeignTenant(): void
    {
        // ROLE_ADMIN trying to backup a sibling tenant they don't own —
        // resolveAdminScope() must throw AccessDeniedException, which
        // Symfony maps to 403.
        $this->loginAsUser($this->tenantAdminUser);
        $this->sameOriginRequest('POST', '/en/admin/data/backup/create', [
            'tenant_id' => (string) $this->foreignTenant->getId(),
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ========== UPLOAD BACKUP TESTS ==========

    #[Test]
    public function testUploadBackupRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/data/backup/upload');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testUploadBackupRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/data/backup/upload');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testUploadBackupRequiresPost(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup/upload');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function testUploadBackupWithoutFileReturnsError(): void
    {
        $this->loginAsUser($this->adminUser);
        // CSRF in controller uses `data_backup_upload` token id.
        $this->sameOriginRequest('POST', '/en/admin/data/backup/upload');
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    // ========== DOWNLOAD BACKUP TESTS ==========

    #[Test]
    public function testDownloadBackupRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/data/backup/download/backup_2024-01-01_00-00-00.json');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testDownloadBackupRejectsInvalidFilename(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup/download/invalid_filename.txt');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function testDownloadBackupRejectsDirectoryTraversal(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup/download/../../../etc/passwd');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== VALIDATE BACKUP TESTS ==========

    #[Test]
    public function testValidateBackupRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/data/backup/validate/backup_2024-01-01_00-00-00.json');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testValidateBackupRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/data/backup/validate/backup_2024-01-01_00-00-00.json');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testValidateBackupRequiresPost(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup/validate/backup_2024-01-01_00-00-00.json');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== PREVIEW RESTORE TESTS ==========

    #[Test]
    public function testPreviewRestoreRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/data/backup/preview/backup_2024-01-01_00-00-00.json');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testPreviewRestoreRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/admin/data/backup/preview/backup_2024-01-01_00-00-00.json');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ========== RESTORE BACKUP TESTS ==========

    #[Test]
    public function testRestoreBackupRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/data/backup/restore/backup_2024-01-01_00-00-00.json');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testRestoreBackupRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/data/backup/restore/backup_2024-01-01_00-00-00.json');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testRestoreBackupRequiresPost(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup/restore/backup_2024-01-01_00-00-00.json');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== DELETE BACKUP TESTS ==========

    #[Test]
    public function testDeleteBackupRequiresAuthentication(): void
    {
        $this->client->request('DELETE', '/en/admin/data/backup/delete/backup_2024-01-01_00-00-00.json');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testDeleteBackupRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('DELETE', '/en/admin/data/backup/delete/backup_2024-01-01_00-00-00.json');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testDeleteBackupRequiresDeleteMethod(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup/delete/backup_2024-01-01_00-00-00.json');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== EXPORT TESTS ==========

    #[Test]
    public function testExportIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/data/export');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testExportIndexRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/admin/data/export');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testExportIndexDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/export');
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testExportExecuteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/data/export/execute');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testExportExecuteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/data/export/execute');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testExportExecuteRequiresPost(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/export/execute');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function testExportExecuteRequiresCsrfToken(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('POST', '/en/admin/data/export/execute');
        // Should redirect due to CSRF or missing entities
        $this->assertResponseRedirects();
    }

    // ========== IMPORT TESTS ==========

    #[Test]
    public function testImportIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/data/import');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testImportIndexRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/admin/data/import');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testImportIndexDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/import');
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testImportUploadRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/data/import/upload');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testImportUploadRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/data/import/upload');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testImportUploadRequiresPost(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/import/upload');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    #[Test]
    public function testImportPreviewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/data/import/preview');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testImportPreviewRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/admin/data/import/preview');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testImportPreviewRedirectsWithoutData(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/import/preview');
        // Should redirect to import index if no data in session
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testImportExecuteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/data/import/execute');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testImportExecuteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/data/import/execute');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testImportExecuteRequiresPost(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/import/execute');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}
