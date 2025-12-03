<?php

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for ProfileMfaController
 *
 * Tests MFA self-service including:
 * - MFA index
 * - TOTP setup
 * - Token verification
 * - Backup code regeneration
 * - Token disable/delete
 */
class ProfileMfaControllerTest extends WebTestCase
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

        $this->entityManager->flush();
    }

    private function loginAsUser(User $user): void
    {
        $this->client->loginUser($user);
    }

    // ========== INDEX TESTS ==========

    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/profile/mfa');
        $this->assertResponseRedirects();
    }

    public function testIndexDisplaysForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/profile/mfa');
        $this->assertResponseIsSuccessful();
    }

    // ========== SETUP TOTP TESTS ==========

    public function testSetupTotpRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/profile/mfa/setup-totp');
        $this->assertResponseRedirects();
    }

    public function testSetupTotpDisplaysForUser(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/profile/mfa/setup-totp');
        $this->assertResponseIsSuccessful();
    }

    // ========== VERIFY TESTS ==========

    public function testVerifyRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/profile/mfa/1/verify');
        $this->assertResponseRedirects();
    }

    public function testVerifyRequiresPost(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/profile/mfa/1/verify');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== REGENERATE BACKUP CODES TESTS ==========

    public function testRegenerateBackupRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/profile/mfa/1/regenerate-backup-codes');
        $this->assertResponseRedirects();
    }

    public function testRegenerateBackupRequiresPost(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/profile/mfa/1/regenerate-backup-codes');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== SHOW TESTS ==========

    public function testShowRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/profile/mfa/1');
        $this->assertResponseRedirects();
    }

    // ========== DISABLE TESTS ==========

    public function testDisableRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/profile/mfa/1/disable');
        $this->assertResponseRedirects();
    }

    public function testDisableRequiresPost(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/profile/mfa/1/disable');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    // ========== DELETE TESTS ==========

    public function testDeleteRequiresAuthentication(): void
    {
        $this->client->request('POST', '/en/profile/mfa/1/delete');
        $this->assertResponseRedirects();
    }

    public function testDeleteRequiresPost(): void
    {
        $this->loginAsUser($this->testUser);
        $this->client->request('GET', '/en/profile/mfa/1/delete');
        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }
}
