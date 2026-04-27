<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\Test;

/**
 * Functional tests for DoraRegisterExportController (MINOR-6).
 *
 * Verifies the ITS-conformant CSV download endpoint contract:
 * - Role gate (ROLE_MANAGER), unauthenticated redirects, ROLE_USER denied.
 * - Content-Type + Content-Disposition headers for Excel-friendly download.
 * - AuditLogger integration: every download creates an export audit row.
 */
class DoraRegisterExportControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $userRoleUser = null;
    private ?User $userRoleManager = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        try {
            $this->entityManager = $container->get(EntityManagerInterface::class);
            $this->entityManager->getConnection()->executeQuery('SELECT 1');
            $this->createTestData();
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Access denied')
                || str_contains($e->getMessage(), 'Connection refused')
                || str_contains($e->getMessage(), 'SQLSTATE')
            ) {
                $this->markTestSkipped('Database not available: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    protected function tearDown(): void
    {
        if (!isset($this->entityManager)) {
            parent::tearDown();
            return;
        }

        foreach ([$this->userRoleUser, $this->userRoleManager] as $u) {
            if ($u !== null) {
                try {
                    $managed = $this->entityManager->find(User::class, $u->getId());
                    if ($managed) {
                        $this->entityManager->remove($managed);
                    }
                } catch (\Exception) {}
            }
        }

        if ($this->testTenant) {
            try {
                $managed = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($managed) {
                    $this->entityManager->remove($managed);
                }
            } catch (\Exception) {}
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception) {}

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('roi_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('ROI Export Tenant ' . $uniqueId);
        $this->testTenant->setCode('roi_' . substr($uniqueId, 0, 20));
        $this->entityManager->persist($this->testTenant);

        $this->userRoleUser = new User();
        $this->userRoleUser->setEmail('user_' . $uniqueId . '@example.com');
        $this->userRoleUser->setFirstName('Role');
        $this->userRoleUser->setLastName('User');
        $this->userRoleUser->setRoles(['ROLE_USER']);
        $this->userRoleUser->setPassword('hashed_password');
        $this->userRoleUser->setTenant($this->testTenant);
        $this->userRoleUser->setIsActive(true);
        $this->entityManager->persist($this->userRoleUser);

        $this->userRoleManager = new User();
        $this->userRoleManager->setEmail('manager_' . $uniqueId . '@example.com');
        $this->userRoleManager->setFirstName('Role');
        $this->userRoleManager->setLastName('Manager');
        $this->userRoleManager->setRoles(['ROLE_MANAGER']);
        $this->userRoleManager->setPassword('hashed_password');
        $this->userRoleManager->setTenant($this->testTenant);
        $this->userRoleManager->setIsActive(true);
        $this->entityManager->persist($this->userRoleManager);

        $this->entityManager->flush();
    }

    #[Test]
    public function testUnauthenticatedRedirectsToLogin(): void
    {
        $this->client->request('GET', '/en/dora-compliance/register-export.csv');
        $response = $this->client->getResponse();

        // Unauthenticated → firewall redirects to login.
        self::assertTrue(
            $response->isRedirect() || $response->getStatusCode() === Response::HTTP_UNAUTHORIZED,
            'Expected redirect to login or 401 for unauthenticated request.',
        );
    }

    #[Test]
    public function testRoleUserIsForbidden(): void
    {
        $this->client->loginUser($this->userRoleUser);
        $this->client->request('GET', '/en/dora-compliance/register-export.csv');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function testRoleManagerReceivesCsvDownload(): void
    {
        $this->client->loginUser($this->userRoleManager);
        $this->client->request('GET', '/en/dora-compliance/register-export.csv');

        $response = $this->client->getResponse();
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());

        $contentType = $response->headers->get('Content-Type');
        self::assertNotNull($contentType);
        self::assertStringContainsString('text/csv', $contentType);

        $disposition = $response->headers->get('Content-Disposition');
        self::assertNotNull($disposition);
        self::assertStringContainsString('attachment', $disposition);
        self::assertStringContainsString('dora-register-of-information-', $disposition);

        // UTF-8 BOM must be present so Excel opens the file correctly.
        self::assertStringStartsWith("\xEF\xBB\xBF", $response->getContent() ?: '');
    }

    #[Test]
    public function testAuditLogEntryCreatedOnExport(): void
    {
        $this->client->loginUser($this->userRoleManager);
        $this->client->request('GET', '/en/dora-compliance/register-export.csv');
        self::assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());

        $logs = $this->entityManager->getRepository(AuditLog::class)->findBy([
            'entityType' => 'DoraRegisterOfInformation',
            'action' => 'export',
        ]);

        self::assertNotEmpty($logs, 'Expected at least one export audit log for DoraRegisterOfInformation.');
    }
}
