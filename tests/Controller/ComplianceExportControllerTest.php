<?php

declare(strict_types=1);

/**
 * ComplianceExportControllerTest
 *
 * Tests for ComplianceExportController — CSV / Excel / PDF export operations.
 * Extracted from ComplianceControllerTest after god-class split.
 */

namespace App\Tests\Controller;

use App\Controller\ComplianceExportController;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\ComplianceAssessmentService;
use App\Service\ComplianceMappingService;
use App\Service\ComplianceRequirementFulfillmentService;
use App\Service\ExcelExportService;
use App\Service\PdfExportService;
use App\Service\TenantContext;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class ComplianceExportControllerTest extends TestCase
{
    private MockObject $complianceFrameworkRepository;
    private MockObject $complianceRequirementRepository;
    private MockObject $complianceMappingRepository;
    private MockObject $complianceAssessmentService;
    private MockObject $complianceMappingService;
    private MockObject $excelExportService;
    private MockObject $pdfExportService;
    private MockObject $complianceRequirementFulfillmentService;
    private MockObject $tenantContext;
    private ComplianceExportController $controller;

    protected function setUp(): void
    {
        $this->complianceFrameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);
        $this->complianceRequirementRepository = $this->createMock(ComplianceRequirementRepository::class);
        $this->complianceMappingRepository = $this->createMock(ComplianceMappingRepository::class);
        $this->complianceAssessmentService = $this->createMock(ComplianceAssessmentService::class);
        $this->complianceMappingService = $this->createMock(ComplianceMappingService::class);
        $this->excelExportService = $this->createMock(ExcelExportService::class);
        $this->pdfExportService = $this->createMock(PdfExportService::class);
        $this->complianceRequirementFulfillmentService = $this->createMock(ComplianceRequirementFulfillmentService::class);
        $this->tenantContext = $this->createMock(TenantContext::class);

        $this->controller = new ComplianceExportController(
            $this->complianceFrameworkRepository,
            $this->complianceRequirementRepository,
            $this->complianceMappingRepository,
            $this->complianceAssessmentService,
            $this->complianceMappingService,
            $this->excelExportService,
            $this->pdfExportService,
            $this->complianceRequirementFulfillmentService,
            $this->tenantContext
        );

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

    private function createFramework(int $id, string $name, string $code): ComplianceFramework
    {
        $framework = $this->createPartialMock(ComplianceFramework::class, ['getName', 'getCode']);
        $framework->method('getName')->willReturn($name);
        $framework->method('getCode')->willReturn($code);
        $reflection = new \ReflectionClass($framework);
        $property = $reflection->getProperty('id');
        $property->setValue($framework, $id);
        $reqProperty = $reflection->getProperty('requirements');
        $reqProperty->setValue($framework, new ArrayCollection());

        return $framework;
    }

    private function createRequirement(int $id, string $requirementId): ComplianceRequirement
    {
        $requirement = $this->createMock(ComplianceRequirement::class);
        $requirement->method('getId')->willReturn($id);
        $requirement->method('getRequirementId')->willReturn($requirementId);
        $requirement->method('getTitle')->willReturn('Test Requirement');
        $requirement->method('getDataSourceMapping')->willReturn([]);
        $requirement->method('hasDetailedRequirements')->willReturn(false);
        $requirement->method('getDetailedRequirements')->willReturn(new ArrayCollection());

        return $requirement;
    }

    #[Test]
    public function testExportDataReuseReturnsCSVResponse(): void
    {
        $framework = $this->createFramework(1, 'ISO 27001', 'ISO27001');
        $requirements = [$this->createRequirement(1, 'A.5.1')];
        $analysis = ['reusable_data' => []];
        $reuseValue = ['estimated_hours_saved' => 20];
        $session = $this->createMock(SessionInterface::class);

        $request = new Request();
        $request->setSession($session);

        $this->complianceFrameworkRepository->method('find')
            ->willReturn($framework);

        $this->complianceRequirementRepository->method('findApplicableByFramework')
            ->willReturn($requirements);

        $this->complianceMappingService->method('getDataReuseAnalysis')
            ->willReturn($analysis);

        $this->complianceMappingService->method('calculateDataReuseValue')
            ->willReturn($reuseValue);

        $response = $this->controller->exportDataReuse($request, 1);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
    }

    #[Test]
    public function testExportDataReuseThrowsNotFoundForInvalidFramework(): void
    {
        $request = new Request();
        $session = $this->createMock(SessionInterface::class);
        $request->setSession($session);

        $this->complianceFrameworkRepository->method('find')
            ->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage('Framework not found');

        $this->controller->exportDataReuse($request, 999);
    }
}
