<?php

declare(strict_types=1);

/**
 * ComplianceControllerTest
 *
 * Tests for ComplianceController dashboard and view routes:
 * - Compliance overview / index
 * - Framework dashboard
 * - Gap analysis
 * - Data-reuse insights
 * - Cross-framework mappings view
 * - Transitive compliance view
 * - Framework comparison view
 * - Framework assessment action
 * - Framework management redirect
 *
 * Export tests: ComplianceExportControllerTest.php
 * Mapping admin tests: ComplianceMappingAdminControllerTest.php
 *
 * Uses PHPUnit 13.1 with proper mocking patterns.
 */

namespace App\Tests\Controller;

use App\Controller\ComplianceController;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\ComplianceAssessmentService;
use App\Service\ComplianceMappingService;
use App\Service\ComplianceRequirementFulfillmentService;
use App\Service\ExcelExportService;
use App\Service\ModuleConfigurationService;
use App\Service\PdfExportService;
use App\Service\TenantContext;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class ComplianceControllerTest extends TestCase
{
    private MockObject $complianceFrameworkRepository;
    private MockObject $complianceRequirementRepository;
    private MockObject $complianceMappingRepository;
    private MockObject $complianceAssessmentService;
    private MockObject $complianceMappingService;
    private MockObject $csrfTokenManager;
    private MockObject $excelExportService;
    private MockObject $moduleConfigurationService;
    private MockObject $pdfExportService;
    private MockObject $complianceRequirementFulfillmentService;
    private MockObject $tenantContext;
    private ComplianceController $controller;

    protected function setUp(): void
    {
        $this->complianceFrameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);
        $this->complianceRequirementRepository = $this->createMock(ComplianceRequirementRepository::class);
        $this->complianceMappingRepository = $this->createMock(ComplianceMappingRepository::class);
        $this->complianceAssessmentService = $this->createMock(ComplianceAssessmentService::class);
        $this->complianceMappingService = $this->createMock(ComplianceMappingService::class);
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $this->excelExportService = $this->createMock(ExcelExportService::class);
        $this->moduleConfigurationService = $this->createMock(ModuleConfigurationService::class);
        $this->pdfExportService = $this->createMock(PdfExportService::class);
        $this->complianceRequirementFulfillmentService = $this->createMock(ComplianceRequirementFulfillmentService::class);
        $this->tenantContext = $this->createMock(TenantContext::class);

        $this->controller = new ComplianceController(
            $this->complianceFrameworkRepository,
            $this->complianceRequirementRepository,
            $this->complianceMappingRepository,
            $this->complianceAssessmentService,
            $this->complianceMappingService,
            $this->csrfTokenManager,
            $this->moduleConfigurationService,
            $this->complianceRequirementFulfillmentService,
            $this->tenantContext
        );

        // Setup container for rendering
        $this->setupControllerContainer();
    }

    private function setupControllerContainer(): void
    {
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturn('<html>Test</html>');

        $router = $this->createMock(\Symfony\Component\Routing\Generator\UrlGeneratorInterface::class);
        $router->method('generate')->willReturn('/test-url');

        $flashBag = $this->createMock(\Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface::class);

        $session = $this->createMock(\Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface::class);
        $session->method('get')->willReturn([]);
        $session->method('getFlashBag')->willReturn($flashBag);

        $request = new Request();
        $request->setSession($session);

        $requestStack = $this->createMock(\Symfony\Component\HttpFoundation\RequestStack::class);
        $requestStack->method('getCurrentRequest')->willReturn($request);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(function ($id) use ($twig, $router, $requestStack) {
            return match ($id) {
                'twig' => $twig,
                'router' => $router,
                'request_stack' => $requestStack,
                default => null,
            };
        });

        $this->controller->setContainer($container);
    }

    #[Test]
    public function testIndexReturnsComplianceOverview(): void
    {
        $frameworks = [$this->createFramework(1, 'ISO 27001', 'ISO27001')];
        $requirements = [$this->createRequirement(1, 'A.5.1')];
        $overview = ['total' => 10, 'compliant' => 5];
        $mappingStats = ['total_mappings' => 100];
        $reuseValue = ['estimated_hours_saved' => 40];

        $this->complianceFrameworkRepository->method('findActiveFrameworks')
            ->willReturn($frameworks);

        $this->complianceFrameworkRepository->method('getComplianceOverview')
            ->willReturn($overview);

        $this->complianceMappingRepository->method('getMappingStatistics')
            ->willReturn($mappingStats);

        $this->complianceRequirementRepository->method('findApplicableByFramework')
            ->willReturn($requirements);

        $this->complianceMappingService->method('calculateDataReuseValue')
            ->willReturn($reuseValue);

        $response = $this->controller->index();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function testFrameworkDashboardReturnsFrameworkDetails(): void
    {
        $framework = $this->createFramework(1, 'ISO 27001', 'ISO27001');
        $tenant = $this->createTenant(1, 'Test Tenant');
        $requirements = [$this->createRequirement(1, 'A.5.1')];
        $dashboard = ['total' => 10, 'compliant' => 5];
        $fulfillment = $this->createMock(ComplianceRequirementFulfillment::class);

        $this->complianceFrameworkRepository->method('find')
            ->willReturn($framework);

        $this->complianceAssessmentService->method('getComplianceDashboard')
            ->willReturn($dashboard);

        $this->complianceRequirementRepository->method('findByFramework')
            ->willReturn($requirements);

        $this->moduleConfigurationService->method('getAllModules')
            ->willReturn([]);

        $this->moduleConfigurationService->method('getActiveModules')
            ->willReturn([]);

        $this->tenantContext->method('getCurrentTenant')
            ->willReturn($tenant);

        $this->complianceRequirementFulfillmentService->method('getOrCreateFulfillment')
            ->willReturn($fulfillment);

        $response = $this->controller->frameworkDashboard(1);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function testFrameworkDashboardThrowsNotFoundForInvalidFramework(): void
    {
        $this->complianceFrameworkRepository->method('find')
            ->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Framework not found');

        $this->controller->frameworkDashboard(999);
    }

    #[Test]
    public function testGapAnalysisReturnsGapsForFramework(): void
    {
        $framework = $this->createFramework(1, 'ISO 27001', 'ISO27001');
        $gaps = [$this->createRequirement(1, 'A.5.1')];
        $criticalGaps = [$this->createRequirement(2, 'A.5.2')];
        $analysis = ['status' => 'not_met', 'coverage' => 0];

        $this->complianceFrameworkRepository->method('find')
            ->willReturn($framework);

        $this->complianceRequirementRepository->method('findGapsByFramework')
            ->willReturn($gaps);

        $this->complianceRequirementRepository->method('findByFrameworkAndPriority')
            ->willReturn($criticalGaps);

        $this->complianceAssessmentService->method('assessRequirement')
            ->willReturn($analysis);

        $response = $this->controller->gapAnalysis(1);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function testGapAnalysisThrowsNotFoundForInvalidFramework(): void
    {
        $this->complianceFrameworkRepository->method('find')
            ->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Framework not found');

        $this->controller->gapAnalysis(999);
    }

    #[Test]
    public function testDataReuseInsightsReturnsAnalysis(): void
    {
        $framework = $this->createFramework(1, 'ISO 27001', 'ISO27001');
        $requirements = [$this->createRequirement(1, 'A.5.1')];
        $analysis = ['reusable_data' => []];
        $reuseValue = ['estimated_hours_saved' => 20];

        $this->complianceFrameworkRepository->method('find')
            ->willReturn($framework);

        $this->complianceRequirementRepository->method('findApplicableByFramework')
            ->willReturn($requirements);

        $this->complianceMappingService->method('getDataReuseAnalysis')
            ->willReturn($analysis);

        $this->complianceMappingService->method('calculateDataReuseValue')
            ->willReturn($reuseValue);

        $response = $this->controller->dataReuseInsights(1);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function testDataReuseInsightsThrowsNotFoundForInvalidFramework(): void
    {
        $this->complianceFrameworkRepository->method('find')
            ->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Framework not found');

        $this->controller->dataReuseInsights(999);
    }

    #[Test]
    public function testCrossFrameworkMappingsReturnsOverview(): void
    {
        $frameworks = [
            $this->createFramework(1, 'ISO 27001', 'ISO27001'),
            $this->createFramework(2, 'NIST CSF', 'NIST_CSF')
        ];
        $coverage = ['coverage_percentage' => 75, 'mapped_requirements' => 30];
        $mappings = [$this->createMock(ComplianceMapping::class)];

        $this->complianceFrameworkRepository->method('findActiveFrameworks')
            ->willReturn($frameworks);

        $this->complianceMappingRepository->method('calculateFrameworkCoverage')
            ->willReturn($coverage);

        $this->complianceMappingRepository->method('findCrossFrameworkMappings')
            ->willReturn($mappings);

        $response = $this->controller->crossFrameworkMappings();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function testTransitiveComplianceReturnsAnalysis(): void
    {
        $frameworks = [
            $this->createFramework(1, 'ISO 27001', 'ISO27001'),
            $this->createFramework(2, 'NIST CSF', 'NIST_CSF'),
        ];

        $this->complianceFrameworkRepository->method('findActiveFrameworks')
            ->willReturn($frameworks);

        // New bulk-load method replaces per-pair calculateFrameworkCoverage / findCrossFrameworkMappings
        $this->complianceMappingRepository->method('findAllCrossFrameworkMappingsBulk')
            ->willReturn([]);

        $response = $this->controller->transitiveCompliance();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function testCompareFrameworksReturnsComparison(): void
    {
        $framework1 = $this->createFramework(1, 'ISO 27001', 'ISO27001');
        $framework2 = $this->createFramework(2, 'NIST CSF', 'NIST_CSF');
        $requirements1 = [$this->createRequirement(1, 'A.5.1')];
        $requirements2 = [$this->createRequirement(2, 'PR.AC-1')];

        $request = new Request(['framework1_id' => 1, 'framework2_id' => 2]);

        $this->complianceFrameworkRepository->method('find')
            ->willReturnCallback(function ($id) use ($framework1, $framework2) {
                return $id === 1 ? $framework1 : $framework2;
            });

        $this->complianceRequirementRepository->method('findByFramework')
            ->willReturnOnConsecutiveCalls($requirements1, $requirements2);

        $this->complianceMappingRepository->method('findCrossFrameworkMappings')
            ->willReturn([]);

        $response = $this->controller->compareFrameworks($request);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
    }

    // Note: testAssessFramework is omitted as it requires flash messages
    // which need FlashBagAwareSessionInterface and are better tested with functional tests

    #[Test]
    public function testAssessFrameworkThrowsNotFoundForInvalidFramework(): void
    {
        $this->complianceFrameworkRepository->method('find')
            ->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Framework not found');

        $this->controller->assessFramework(999);
    }

    #[Test]
    public function testManageFrameworksRedirectsToAdminIndex(): void
    {
        $response = $this->controller->manageFrameworks();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue($response->isRedirect());
    }

    // Helper methods

    private function createFramework(int $id, string $name, string $code): ComplianceFramework
    {
        $framework = $this->createPartialMock(ComplianceFramework::class, ['getName', 'getCode']);
        $framework->method('getName')->willReturn($name);
        $framework->method('getCode')->willReturn($code);
        $reflection = new \ReflectionClass($framework);
        // Set id
        $property = $reflection->getProperty('id');
        $property->setValue($framework, $id);
        // Initialize the requirements collection so ->count() doesn't throw on uninitialized typed property
        $reqProperty = $reflection->getProperty('requirements');
        $reqProperty->setValue($framework, new \Doctrine\Common\Collections\ArrayCollection());

        return $framework;
    }

    private function createRequirement(int $id, string $requirementId, array $dataSourceMapping = []): ComplianceRequirement
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getId')->willReturn($id);
        $requirement->method('getRequirementId')->willReturn($requirementId);
        $requirement->method('getTitle')->willReturn('Test Requirement');
        $requirement->method('getDataSourceMapping')->willReturn($dataSourceMapping);
        $requirement->method('hasDetailedRequirements')->willReturn(false);
        $requirement->method('getDetailedRequirements')->willReturn(new ArrayCollection());

        return $requirement;
    }

    private function createTenant(int $id, string $name): Tenant
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        $tenant->method('getName')->willReturn($name);

        return $tenant;
    }
}
