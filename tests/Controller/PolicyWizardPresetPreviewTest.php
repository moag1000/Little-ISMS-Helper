<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\IndustryPresetBundle;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for PolicyWizardController::presetPreview — Sprint W4-B.
 *
 * Smoke-tests the JSON contract that the Stimulus picker controller
 * relies on: GET /policy-wizard/preset-preview/{key} returns the
 * bundle's preselected standards, document estimate and metadata.
 */
class PolicyWizardPresetPreviewTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $cisoUser = null;
    private ?User $unprivilegedUser = null;
    private ?IndustryPresetBundle $testBundle = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();

        try {
            $this->entityManager = $container->get(EntityManagerInterface::class);
            $this->entityManager->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            if (
                str_contains($e->getMessage(), 'Access denied')
                || str_contains($e->getMessage(), 'Connection refused')
                || str_contains($e->getMessage(), 'SQLSTATE')
            ) {
                $this->markTestSkipped('Database not available: ' . $e->getMessage());
            }
            throw $e;
        }

        $lockFile = $container->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lockFile)) {
            @file_put_contents($lockFile, date('c'));
        }

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if (!isset($this->entityManager)) {
            parent::tearDown();
            return;
        }

        try {
            if ($this->testBundle !== null && $this->testBundle->getId() !== null) {
                $managed = $this->entityManager->find(IndustryPresetBundle::class, $this->testBundle->getId());
                if ($managed !== null) {
                    $this->entityManager->remove($managed);
                    $this->entityManager->flush();
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        foreach ([$this->cisoUser, $this->unprivilegedUser] as $u) {
            if ($u === null) {
                continue;
            }
            try {
                $managed = $this->entityManager->find(User::class, $u->getId());
                if ($managed !== null) {
                    $this->entityManager->remove($managed);
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if ($this->testTenant !== null) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($tenant !== null) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Throwable) {
            // ignore
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('pwp_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('PresetPreview Tenant ' . $uniqueId);
        $this->testTenant->setCode('pwp_' . substr($uniqueId, 0, 18));
        $this->entityManager->persist($this->testTenant);

        $this->cisoUser = new User();
        $this->cisoUser->setEmail('ciso_' . $uniqueId . '@example.test');
        $this->cisoUser->setFirstName('Chief');
        $this->cisoUser->setLastName('Security');
        $this->cisoUser->setRoles(['ROLE_USER', 'ROLE_CISO']);
        $this->cisoUser->setPassword('hashed_password');
        $this->cisoUser->setTenant($this->testTenant);
        $this->cisoUser->setIsActive(true);
        $this->entityManager->persist($this->cisoUser);

        $this->unprivilegedUser = new User();
        $this->unprivilegedUser->setEmail('user_' . $uniqueId . '@example.test');
        $this->unprivilegedUser->setFirstName('Plain');
        $this->unprivilegedUser->setLastName('User');
        $this->unprivilegedUser->setRoles(['ROLE_USER']);
        $this->unprivilegedUser->setPassword('hashed_password');
        $this->unprivilegedUser->setTenant($this->testTenant);
        $this->unprivilegedUser->setIsActive(true);
        $this->entityManager->persist($this->unprivilegedUser);

        // Use a unique key per test run so we never collide with seeded
        // production bundles in the test database.
        $this->testBundle = new IndustryPresetBundle();
        $this->testBundle
            ->setKey('test_' . substr($uniqueId, 0, 18))
            ->setLabel('Test Preset ' . $uniqueId)
            ->setDescription('Functional-test preset bundle.')
            ->setStandard(IndustryPresetBundle::STANDARD_ISO_GDPR)
            ->setPreselectedStandards(['iso27001', 'gdpr'])
            ->setDefaultRiskAppetiteTier(2)
            ->setDefaultDataClassificationLevels(4)
            ->setDefaultBackupRpoHours(8)
            ->setDefaultPatchSlaCriticalHours(48)
            ->setDpoSectionsAutoEnabled(true)
            ->setRegulatoryReferences(['Test Reg 1', 'Test Reg 2'])
            ->setIsActive(true)
            ->setVersion(1);
        $this->entityManager->persist($this->testBundle);

        $this->entityManager->flush();
    }

    #[Test]
    public function testPreviewReturns404ForUnknownKey(): void
    {
        $this->client->loginUser($this->cisoUser);
        $this->client->request('GET', '/en/policy-wizard/preset-preview/does_not_exist');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
        $this->assertResponseHeaderSame('Content-Type', 'application/json');
    }

    #[Test]
    public function testPreviewReturnsExpectedJsonForKnownBundle(): void
    {
        $this->client->loginUser($this->cisoUser);
        $this->client->request(
            'GET',
            '/en/policy-wizard/preset-preview/' . $this->testBundle->getKey(),
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');

        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($payload);
        self::assertSame($this->testBundle->getKey(), $payload['key'] ?? null);
        self::assertSame(['iso27001', 'gdpr'], $payload['preselected_standards'] ?? null);
        self::assertSame(2, $payload['default_risk_appetite_tier'] ?? null);
        self::assertSame(8, $payload['default_backup_rpo_hours'] ?? null);
        self::assertTrue($payload['dpo_sections_auto_enabled'] ?? false);
        // ~4 documents per pre-selected standard.
        self::assertSame(8, $payload['estimated_document_count'] ?? null);
        self::assertContains('Test Reg 1', $payload['regulatory_references'] ?? []);
    }

    #[Test]
    public function testPreviewIsForbiddenForUnprivilegedUser(): void
    {
        $this->client->loginUser($this->unprivilegedUser);
        $this->client->request(
            'GET',
            '/en/policy-wizard/preset-preview/' . $this->testBundle->getKey(),
        );

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}
