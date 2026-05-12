<?php

declare(strict_types=1);

namespace App\Tests\Controller\Authority;

use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional smoke tests for VvtExportController.
 *
 * Tests:
 *  - XLSX endpoint streams a valid XLSX response for ROLE_DPO
 *  - CSV endpoint streams a valid CSV response
 *  - PDF endpoint returns a PDF response
 *  - 404 when no ProcessingActivities are recorded (friendly error page)
 *  - 403 for non-DPO users
 *  - Anonymous users are redirected to login
 */
#[AllowMockObjectsWithoutExpectations]
class VvtExportControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $dpoUser = null;
    private ?User $regularUser = null;
    private ?ProcessingActivity $activity = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        // Mock ModuleConfigurationService — privacy + all modules active
        $moduleService = $this->createMock(\App\Service\ModuleConfigurationService::class);
        $moduleService->method('isModuleActive')->willReturnCallback(
            static fn (string $key): bool => in_array($key, [
                'core', 'authentication', 'assets', 'risks', 'controls',
                'incidents', 'audits', 'training', 'reviews', 'bcm',
                'compliance', 'audit_logging', 'privacy', 'nis2_dora',
                'ai_governance', 'cloud_security', 'vulnerability_intel',
                'marisk', 'tisax', 'quantitative_risk', 'notifications',
                'eu_authority_reporting', 'tisax_isa', 'ai_act', 'cra_sbom', 'procedures',
            ], true),
        );
        $container->set(\App\Service\ModuleConfigurationService::class, $moduleService);

        $this->em = $container->get(EntityManagerInterface::class);

        $this->createTestData();
    }

    protected function tearDown(): void
    {
        $entitiesToRemove = array_filter([
            $this->activity ? $this->em->find(ProcessingActivity::class, $this->activity->getId()) : null,
            $this->dpoUser ? $this->em->find(User::class, $this->dpoUser->getId()) : null,
            $this->regularUser ? $this->em->find(User::class, $this->regularUser->getId()) : null,
            $this->tenant ? $this->em->find(Tenant::class, $this->tenant->getId()) : null,
        ]);

        foreach ($entitiesToRemove as $entity) {
            try {
                $this->em->remove($entity);
            } catch (\Throwable) {
                // ignore
            }
        }

        try {
            $this->em->flush();
        } catch (\Throwable) {
            // ignore
        }

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uid = uniqid('vvt_', true);

        $this->tenant = new Tenant();
        $this->tenant->setName('VVT Test AG ' . $uid);
        $this->tenant->setCode('vvt_' . substr($uid, 0, 12));
        $this->tenant->setDpoContactName('Dr. Datenschutz');
        $this->tenant->setDpoContactEmail('dpo@vvt.example.com');
        $this->em->persist($this->tenant);

        // DPO user (has ROLE_DPO which inherits ROLE_MANAGER)
        $this->dpoUser = new User();
        $this->dpoUser->setEmail('dpo_' . $uid . '@example.com');
        $this->dpoUser->setFirstName('Data');
        $this->dpoUser->setLastName('Officer');
        $this->dpoUser->setRoles(['ROLE_DPO']);
        $this->dpoUser->setPassword('hashed_password');
        $this->dpoUser->setTenant($this->tenant);
        $this->dpoUser->setIsActive(true);
        $this->em->persist($this->dpoUser);

        // Regular user (no DPO)
        $this->regularUser = new User();
        $this->regularUser->setEmail('regular_' . $uid . '@example.com');
        $this->regularUser->setFirstName('Regular');
        $this->regularUser->setLastName('User');
        $this->regularUser->setRoles(['ROLE_USER']);
        $this->regularUser->setPassword('hashed_password');
        $this->regularUser->setTenant($this->tenant);
        $this->regularUser->setIsActive(true);
        $this->em->persist($this->regularUser);

        // A ProcessingActivity for the tenant so exports work
        $this->activity = new ProcessingActivity();
        $this->activity->setName('Kundenverwaltung ' . $uid);
        $this->activity->setPurposes(['Vertragserfüllung']);
        $this->activity->setDataSubjectCategories(['customers']);
        $this->activity->setPersonalDataCategories(['identification', 'contact']);
        $this->activity->setLegalBasis('contract');
        $this->activity->setRetentionPeriod('10 Jahre');
        $this->activity->setHasThirdCountryTransfer(false);
        $this->activity->setTenant($this->tenant);
        $this->em->persist($this->activity);

        $this->em->flush();
    }

    // ─── Anonymous access ─────────────────────────────────────────────────────

    #[Test]
    public function anonymousUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/de/verfahrensverzeichnis/export/xlsx');
        $this->assertResponseRedirects();
        $location = $this->client->getResponse()->headers->get('Location');
        $this->assertStringContainsString('login', (string) $location);
    }

    // ─── Permission tests ─────────────────────────────────────────────────────

    #[Test]
    public function regularUserCannotAccessXlsxEndpoint(): void
    {
        $this->client->loginUser($this->regularUser);
        $this->client->request('GET', '/de/verfahrensverzeichnis/export/xlsx');

        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains($statusCode, [
            Response::HTTP_FORBIDDEN,
            Response::HTTP_FOUND, // redirected to login/access-denied
        ]);
    }

    // ─── XLSX download ────────────────────────────────────────────────────────

    #[Test]
    public function dpoUserCanDownloadXlsx(): void
    {
        $this->client->loginUser($this->dpoUser);
        $this->client->request('GET', '/de/verfahrensverzeichnis/export/xlsx');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );
        $this->assertStringContainsString(
            'attachment',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString(
            '.xlsx',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    // ─── CSV download ─────────────────────────────────────────────────────────

    #[Test]
    public function dpoUserCanDownloadCsv(): void
    {
        $this->client->loginUser($this->dpoUser);
        $this->client->request('GET', '/de/verfahrensverzeichnis/export/csv');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString(
            'text/csv',
            (string) $this->client->getResponse()->headers->get('Content-Type'),
        );
        $this->assertStringContainsString(
            'attachment',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    // ─── PDF download ─────────────────────────────────────────────────────────

    #[Test]
    public function dpoUserCanDownloadPdf(): void
    {
        $this->client->loginUser($this->dpoUser);
        $this->client->request('GET', '/de/verfahrensverzeichnis/export/pdf');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
        $this->assertStringContainsString(
            'attachment',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    // ─── No activities — 404 with friendly page ───────────────────────────────

    #[Test]
    public function xlsxEndpointReturns404WhenNoActivitiesExist(): void
    {
        // Remove the test activity
        if ($this->activity !== null) {
            $toRemove = $this->em->find(ProcessingActivity::class, $this->activity->getId());
            if ($toRemove !== null) {
                $this->em->remove($toRemove);
                $this->em->flush();
            }
            $this->activity = null;
        }

        $this->client->loginUser($this->dpoUser);
        $this->client->request('GET', '/de/verfahrensverzeichnis/export/xlsx');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function csvEndpointReturns404WhenNoActivitiesExist(): void
    {
        if ($this->activity !== null) {
            $toRemove = $this->em->find(ProcessingActivity::class, $this->activity->getId());
            if ($toRemove !== null) {
                $this->em->remove($toRemove);
                $this->em->flush();
            }
            $this->activity = null;
        }

        $this->client->loginUser($this->dpoUser);
        $this->client->request('GET', '/de/verfahrensverzeichnis/export/csv');

        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }
}
