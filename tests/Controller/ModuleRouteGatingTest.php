<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Wiring test for #[RequiresModule] (RequiresModuleListener): the
 * framework-specific pages that used to be reachable with their module
 * switched off — DORA/NIS2, LkSG, BSI IT-Grundschutz, MRIS, training,
 * suppliers, persona dashboards and the operational modules — now redirect
 * to the dashboard when the tenant has not activated the module.
 */
#[AllowMockObjectsWithoutExpectations]
final class ModuleRouteGatingTest extends WebTestCase
{
    /** Every module of config/modules.yaml that is optional or required. */
    private const ALL_MODULES = [
        'core', 'authentication', 'audit_logging', 'documents', 'objectives', 'workflows',
        'assets', 'risks', 'controls', 'incidents', 'audits', 'training', 'reviews', 'bcm',
        'compliance', 'privacy', 'nis2_dora', 'ai_governance', 'cloud_security',
        'vulnerability_intel', 'marisk', 'lksg', 'tisax', 'risk_quant', 'notifications',
        'eu_authority_reporting', 'answer_library', 'suppliers', 'locations', 'persons',
        'interested_parties', 'change_requests', 'patches', 'corrective_actions',
        'risk_appetite', 'risk_treatment_plans', 'analytics', 'report_builder',
        'scheduled_reports', 'portfolio_reports', 'mris', 'bsi_grundschutz',
        'resource_planning', 'ocr_processing', 'access_review',
    ];

    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $tenant = null;
    private ?User $user = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);

        $lockFile = $container->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lockFile)) {
            @file_put_contents($lockFile, date('c'));
        }

        $uid = uniqid('modgate_', true);
        $this->tenant = new Tenant();
        $this->tenant->setName('Module Route Gate ' . $uid);
        $this->tenant->setCode('mrg_' . substr(md5($uid), 0, 10));
        $this->entityManager->persist($this->tenant);

        $this->user = new User();
        $this->user->setEmail('mrg_' . md5($uid) . '@example.com');
        $this->user->setFirstName('Module');
        $this->user->setLastName('Gate');
        $this->user->setRoles(['ROLE_ADMIN']);
        $this->user->setPassword('hashed_password');
        $this->user->setTenant($this->tenant);
        $this->user->setIsActive(true);
        $this->entityManager->persist($this->user);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        foreach ([$this->user, $this->tenant] as $entity) {
            if ($entity === null || $entity->getId() === null) {
                continue;
            }
            try {
                $managed = $this->entityManager->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->entityManager->remove($managed);
                    $this->entityManager->flush();
                }
            } catch (\Throwable) {
            }
        }

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function gatedRoutes(): iterable
    {
        yield 'training index' => ['/en/training', 'training'];
        yield 'training report pdf' => ['/en/reports/trainings/pdf', 'training'];
        yield 'DORA dashboard' => ['/en/dora-compliance', 'nis2_dora'];
        yield 'DORA register export' => ['/en/dora-compliance/register-export.csv', 'nis2_dora'];
        yield 'ICT provider library' => ['/en/ict-provider-library', 'nis2_dora'];
        yield 'LkSG annual report' => ['/en/supplier/lksg-annual-report.csv', 'lksg'];
        yield 'BSI Grundschutz check' => ['/en/bsi-grundschutz-check', 'bsi_grundschutz'];
        yield 'GSTOOL import' => ['/en/admin/import/gstool', 'bsi_grundschutz'];
        yield 'supplier questionnaires' => ['/en/supplier-questionnaire', 'suppliers'];
        yield 'supplier criticality' => ['/en/admin/supplier-criticality', 'suppliers'];
        yield 'MRIS glossary' => ['/en/mris/glossar', 'mris'];
        yield 'analytics' => ['/en/analytics', 'analytics'];
        yield 'risk appetite' => ['/en/risk-appetite', 'risk_appetite'];
        yield 'risk treatment plans' => ['/en/risk-treatment-plan', 'risk_treatment_plans'];
        yield 'scheduled reports' => ['/en/reports/scheduled/', 'scheduled_reports'];
        yield 'persons' => ['/en/person', 'persons'];
        yield 'interested parties' => ['/en/interested-party', 'interested_parties'];
        yield 'corrective actions' => ['/en/corrective-action/', 'corrective_actions'];
        yield 'BCM management report' => ['/en/reports/management/bcm', 'bcm'];
        yield 'GDPR management report' => ['/en/reports/management/gdpr', 'privacy'];
    }

    #[Test]
    #[DataProvider('gatedRoutes')]
    public function routeRedirectsToDashboardWhenModuleIsInactive(string $url, string $module): void
    {
        $this->activateAllModulesExcept($module);
        $this->client->loginUser($this->user);

        $this->client->request('GET', $url);

        self::assertResponseRedirects(
            '/en/dashboard',
            null,
            sprintf('%s must be blocked while module "%s" is inactive', $url, $module),
        );
        $flashes = $this->client->getRequest()->getSession()->getFlashBag()->peek('warning');
        self::assertNotEmpty(
            array_filter($flashes, static fn (string $f): bool => str_contains($f, $module)),
            'the module-not-active flash must name the module',
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function reachableRoutes(): iterable
    {
        yield 'training index' => ['/en/training', 'training'];
        yield 'MRIS glossary' => ['/en/mris/glossar', 'mris'];
        yield 'BSI Grundschutz check' => ['/en/bsi-grundschutz-check', 'bsi_grundschutz'];
        yield 'supplier criticality' => ['/en/admin/supplier-criticality', 'suppliers'];
        yield 'persons' => ['/en/person', 'persons'];
    }

    #[Test]
    #[DataProvider('reachableRoutes')]
    public function routeIsReachableWhenModuleIsActive(string $url, string $module): void
    {
        $this->activateAllModulesExcept(null);
        $this->client->loginUser($this->user);

        $this->client->request('GET', $url);

        self::assertResponseIsSuccessful(sprintf('%s must render while module "%s" is active', $url, $module));
    }

    #[Test]
    public function xhrCallerGets403JsonWhenModuleIsInactive(): void
    {
        $this->activateAllModulesExcept('nis2_dora');
        $this->client->loginUser($this->user);

        $this->client->request('GET', '/en/ict-provider-library', server: [
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        self::assertResponseStatusCodeSame(403);
        $payload = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('module_not_active', $payload['error'] ?? null);
        self::assertSame('nis2_dora', $payload['module'] ?? null);
    }

    private function activateAllModulesExcept(?string $inactive): void
    {
        $active = array_values(array_filter(self::ALL_MODULES, static fn (string $m): bool => $m !== $inactive));
        $mock = $this->createMock(ModuleConfigurationService::class);
        $mock->method('isModuleActive')->willReturnCallback(
            static fn (string $key): bool => in_array($key, $active, true),
        );
        $mock->method('getActiveModules')->willReturn($active);
        static::getContainer()->set(ModuleConfigurationService::class, $mock);
    }
}
