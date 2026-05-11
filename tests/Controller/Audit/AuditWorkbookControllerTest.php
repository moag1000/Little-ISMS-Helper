<?php

declare(strict_types=1);

namespace App\Tests\Controller\Audit;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Audit\AuditWorkbookGenerator;
use App\Service\Audit\Generator\AuditWorkbookGeneratorInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Smoke tests for AuditWorkbookController (F40.3)
 *
 * Tests all 5 endpoints:
 *   - GET /{locale}/audit-workbook/                            → HTML index
 *   - GET /{locale}/audit-workbook/soa/{id}.xlsx               → XLSX stream
 *   - GET /{locale}/audit-workbook/control-implementation.xlsx → XLSX stream
 *   - GET /{locale}/audit-workbook/compliance/{id}.xlsx        → XLSX stream
 *   - GET /{locale}/audit-workbook/risk-register.xlsx          → XLSX stream
 *
 * Uses the BCExerciseControllerTest setUp pattern (disableReboot +
 * ModuleConfigurationService mock) per memory feedback_csrf_tests_session.
 *
 * AuditWorkbookGenerator is final and cannot be createMock()'d — instead we
 * substitute a real AuditWorkbookGenerator constructed with a single fake
 * AuditWorkbookGeneratorInterface that returns a lightweight StreamedResponse.
 */
#[AllowMockObjectsWithoutExpectations]
class AuditWorkbookControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $auditorUser = null;
    private ?ComplianceFramework $testFramework = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        // Mock ModuleConfigurationService — identical allow-list to BCExerciseControllerTest
        $moduleService = $this->createMock(\App\Service\ModuleConfigurationService::class);
        $moduleService->method('isModuleActive')->willReturnCallback(
            fn(string $key) => in_array($key, [
                'core', 'authentication', 'assets', 'risks', 'controls',
                'incidents', 'audits', 'training', 'reviews', 'bcm',
                'compliance', 'audit_logging', 'privacy', 'nis2_dora',
                'ai_governance', 'cloud_security', 'vulnerability_intel',
                'marisk', 'tisax', 'quantitative_risk', 'notifications',
                'eu_authority_reporting', 'tisax_isa', 'ai_act', 'cra_sbom', 'procedures',
            ], true)
        );
        $container->set(\App\Service\ModuleConfigurationService::class, $moduleService);

        // AuditWorkbookGenerator is final — substitute a real instance built
        // with a single fake inner generator that emits a tiny StreamedResponse.
        $fakeInnerGenerator = new class implements AuditWorkbookGeneratorInterface {
            public function supportsExportType(string $exportType): bool
            {
                return true; // accept every export type
            }

            public function generate(Tenant $tenant, array $options = []): \PhpOffice\PhpSpreadsheet\Spreadsheet
            {
                // Return an empty spreadsheet — never actually written to disk in tests.
                return new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            }
        };
        $fakeGenerator = new AuditWorkbookGenerator([$fakeInnerGenerator]);
        $container->set(AuditWorkbookGenerator::class, $fakeGenerator);

        $this->entityManager = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testFramework) {
            try {
                $fw = $this->entityManager->find(ComplianceFramework::class, $this->testFramework->getId());
                if ($fw) {
                    $this->entityManager->remove($fw);
                }
            } catch (\Exception) {
            }
        }

        if ($this->auditorUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->auditorUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception) {
            }
        }

        if ($this->testTenant) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Exception) {
            }
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception) {
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('awb_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('AWB Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('awb_' . substr(preg_replace('/[^a-z0-9]/', '', strtolower($uniqueId)), 0, 20));
        $this->entityManager->persist($this->testTenant);

        $this->auditorUser = new User();
        $this->auditorUser->setEmail('auditor_' . $uniqueId . '@example.com');
        $this->auditorUser->setFirstName('Auditor');
        $this->auditorUser->setLastName('Test');
        $this->auditorUser->setRoles(['ROLE_AUDITOR']);
        $this->auditorUser->setPassword('hashed_password');
        $this->auditorUser->setTenant($this->testTenant);
        $this->auditorUser->setIsActive(true);
        $this->entityManager->persist($this->auditorUser);

        $this->testFramework = new ComplianceFramework();
        $this->testFramework->setName('Test Framework ' . $uniqueId);
        $this->testFramework->setCode('AWB_' . substr(preg_replace('/[^A-Z0-9]/', '', strtoupper($uniqueId)), 0, 12));
        $this->testFramework->setDescription('Audit workbook test framework');
        $this->testFramework->setVersion('1.0');
        $this->testFramework->setApplicableIndustry('general');
        $this->testFramework->setRegulatoryBody('Test Body');
        $this->testFramework->setActive(true);
        $this->testFramework->setMandatory(false);
        $this->testFramework->setCreatedAt(new \DateTimeImmutable());
        $this->entityManager->persist($this->testFramework);

        $this->entityManager->flush();
    }

    // ── Index endpoint ────────────────────────────────────────────────────

    #[Test]
    public function testAnonymousUserGetsRedirect(): void
    {
        $this->client->request('GET', '/en/audit-workbook/');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testIndexRendersHtml(): void
    {
        $this->client->loginUser($this->auditorUser);
        $this->client->request('GET', '/en/audit-workbook/');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('main.fa-aurora-surface');
    }

    // ── SoA endpoint ──────────────────────────────────────────────────────

    #[Test]
    public function testSoaXlsxRequiresValidFramework(): void
    {
        $this->client->loginUser($this->auditorUser);
        $this->client->request('GET', '/en/audit-workbook/soa/999999.xlsx');
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function testSoaXlsxStreamsXlsxResponse(): void
    {
        $this->client->loginUser($this->auditorUser);
        $this->client->request('GET', '/en/audit-workbook/soa/' . $this->testFramework->getId() . '.xlsx');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    // ── Control Implementation endpoint ──────────────────────────────────

    #[Test]
    public function testControlImplementationXlsxStreams(): void
    {
        $this->client->loginUser($this->auditorUser);
        $this->client->request('GET', '/en/audit-workbook/control-implementation.xlsx');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    // ── Compliance Fulfillment endpoint ───────────────────────────────────

    #[Test]
    public function testComplianceXlsxStreams(): void
    {
        $this->client->loginUser($this->auditorUser);
        $this->client->request('GET', '/en/audit-workbook/compliance/' . $this->testFramework->getId() . '.xlsx');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }

    // ── Risk Register endpoint ────────────────────────────────────────────

    #[Test]
    public function testRiskRegisterXlsxStreams(): void
    {
        $this->client->loginUser($this->auditorUser);
        $this->client->request('GET', '/en/audit-workbook/risk-register.xlsx');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame(
            'content-type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );
    }
}
