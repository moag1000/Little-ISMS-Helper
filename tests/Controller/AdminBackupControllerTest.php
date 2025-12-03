<?php

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

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
        $this->adminUser->setRoles(['ROLE_ADMIN']);
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

    // ========== BACKUP INDEX TESTS ==========

    public function testBackupIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/data/backup');
        $this->assertResponseRedirects();
    }

    public function testBackupIndexRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/admin/data/backup');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testBackupIndexDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup');
        $this->assertResponseIsSuccessful();
    }

    // ========== CREATE BACKUP TESTS ==========

    public function testCreateBackupRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/data/backup/create');
        $this->assertResponseRedirects();
    }

    public function testCreateBackupRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/data/backup/create');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testCreateBackupRequiresPost(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup/create');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testCreateBackupExecutesForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('POST', '/en/admin/data/backup/create');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ========== UPLOAD BACKUP TESTS ==========

    public function testUploadBackupRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/data/backup/upload');
        $this->assertResponseRedirects();
    }

    public function testUploadBackupRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/data/backup/upload');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testUploadBackupRequiresPost(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup/upload');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testUploadBackupWithoutFileReturnsError(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('POST', '/en/admin/data/backup/upload');
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    // ========== DOWNLOAD BACKUP TESTS ==========

    public function testDownloadBackupRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/data/backup/download/backup_2024-01-01_00-00-00.json');
        $this->assertResponseRedirects();
    }

    public function testDownloadBackupRejectsInvalidFilename(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup/download/invalid_filename.txt');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    public function testDownloadBackupRejectsDirectoryTraversal(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup/download/../../../etc/passwd');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // ========== VALIDATE BACKUP TESTS ==========

    public function testValidateBackupRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/data/backup/validate/backup_2024-01-01_00-00-00.json');
        $this->assertResponseRedirects();
    }

    public function testValidateBackupRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/data/backup/validate/backup_2024-01-01_00-00-00.json');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testValidateBackupRequiresPost(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup/validate/backup_2024-01-01_00-00-00.json');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== PREVIEW RESTORE TESTS ==========

    public function testPreviewRestoreRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/data/backup/preview/backup_2024-01-01_00-00-00.json');
        $this->assertResponseRedirects();
    }

    public function testPreviewRestoreRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/admin/data/backup/preview/backup_2024-01-01_00-00-00.json');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ========== RESTORE BACKUP TESTS ==========

    public function testRestoreBackupRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/data/backup/restore/backup_2024-01-01_00-00-00.json');
        $this->assertResponseRedirects();
    }

    public function testRestoreBackupRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/data/backup/restore/backup_2024-01-01_00-00-00.json');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testRestoreBackupRequiresPost(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup/restore/backup_2024-01-01_00-00-00.json');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== DELETE BACKUP TESTS ==========

    public function testDeleteBackupRequiresAuthentication(): void
    {
        $this->client->request('DELETE', '/en/admin/data/backup/delete/backup_2024-01-01_00-00-00.json');
        $this->assertResponseRedirects();
    }

    public function testDeleteBackupRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('DELETE', '/en/admin/data/backup/delete/backup_2024-01-01_00-00-00.json');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testDeleteBackupRequiresDeleteMethod(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/backup/delete/backup_2024-01-01_00-00-00.json');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== EXPORT TESTS ==========

    public function testExportIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/data/export');
        $this->assertResponseRedirects();
    }

    public function testExportIndexRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/admin/data/export');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testExportIndexDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/export');
        $this->assertResponseIsSuccessful();
    }

    public function testExportExecuteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/data/export/execute');
        $this->assertResponseRedirects();
    }

    public function testExportExecuteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/data/export/execute');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testExportExecuteRequiresPost(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/export/execute');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testExportExecuteRequiresCsrfToken(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('POST', '/en/admin/data/export/execute');
        // Should redirect due to CSRF or missing entities
        $this->assertResponseRedirects();
    }

    // ========== IMPORT TESTS ==========

    public function testImportIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/data/import');
        $this->assertResponseRedirects();
    }

    public function testImportIndexRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/admin/data/import');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testImportIndexDisplaysForAdmin(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/import');
        $this->assertResponseIsSuccessful();
    }

    public function testImportUploadRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/data/import/upload');
        $this->assertResponseRedirects();
    }

    public function testImportUploadRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/data/import/upload');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testImportUploadRequiresPost(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/import/upload');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    public function testImportPreviewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/admin/data/import/preview');
        $this->assertResponseRedirects();
    }

    public function testImportPreviewRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/admin/data/import/preview');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testImportPreviewRedirectsWithoutData(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/import/preview');
        // Should redirect to import index if no data in session
        $this->assertResponseRedirects();
    }

    public function testImportExecuteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/admin/data/import/execute');
        $this->assertResponseRedirects();
    }

    public function testImportExecuteRequiresAdminRole(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('POST', '/en/admin/data/import/execute');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testImportExecuteRequiresPost(): void
    {
        $this->loginAsUser($this->adminUser);
        $this->client->request('GET', '/en/admin/data/import/execute');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}
