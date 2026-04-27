<?php

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
    private ?User $testUser = null;
    private ?User $adminUser = null;

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
        // SUPER_ADMIN needed because backup index/download/upload/validate/preview/delete
        // all require ROLE_SUPER_ADMIN (global-data operations per 04bae7acf + Prio-C).
        $this->adminUser->setRoles(['ROLE_SUPER_ADMIN']);
        $this->adminUser->setPassword('hashed_password');
        $this->adminUser->setTenant($this->testTenant);
        $this->adminUser->setIsActive(true);
        $this->entityManager->persist($this->adminUser);

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
            ['HTTP_SEC_FETCH_SITE' => 'same-origin']
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
