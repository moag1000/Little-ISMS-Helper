<?php

declare(strict_types=1);

namespace App\Tests\Controller\Dashboard;

use App\Controller\Dashboard\ComplianceManagerDashboardController;
use App\Entity\Tenant;
use App\Repository\AuditFindingRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\DocumentRepository;
use App\Repository\InternalAuditRepository;
use App\Service\ComplianceAnalyticsService;
use App\Service\TenantContext;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * V3 W2-M2 — Compliance-Manager-Dashboard tests.
 *
 * Verifies field-naming bug-fix:
 *   1. Heatmap reads `compliance_percentage` (service field) → mapped onto `compliance` (template field).
 *   2. Top-3 lowest sorted on the same normalized field.
 *   3. Summary `at_risk` + `cross_mapping_coverage` exist (KPI tiles).
 */
#[AllowMockObjectsWithoutExpectations]
class ComplianceManagerDashboardControllerTest extends TestCase
{
    private MockObject $analytics;
    private MockObject $frameworkRepo;
    private MockObject $findingRepo;
    private MockObject $auditRepo;
    private MockObject $documentRepo;
    private MockObject $tenantContext;
    private MockObject $requirementRepo;
    private MockObject $container;
    private MockObject $twig;
    private ComplianceManagerDashboardController $controller;
    private ?array $renderedDashboard = null;

    protected function setUp(): void
    {
        $this->analytics = $this->createMock(ComplianceAnalyticsService::class);
        $this->frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $this->findingRepo = $this->createMock(AuditFindingRepository::class);
        $this->auditRepo = $this->createMock(InternalAuditRepository::class);
        $this->documentRepo = $this->createMock(DocumentRepository::class);
        $this->requirementRepo = $this->createMock(ComplianceRequirementRepository::class);
        $this->tenantContext = $this->createMock(TenantContext::class);
        $this->container = $this->createMock(ContainerInterface::class);
        $this->twig = $this->createMock(Environment::class);

        $self = $this;
        $this->container->method('has')->willReturnCallback(static fn(string $id) => in_array($id, ['twig'], true));
        $this->container->method('get')->willReturnCallback(static function (string $id) use ($self) {
            return match ($id) {
                'twig' => $self->twig,
                default => null,
            };
        });

        // Capture the rendered dashboard payload via Twig
        $this->twig->method('render')->willReturnCallback(function (string $tpl, array $ctx = []) {
            $this->renderedDashboard = $ctx['dashboard'] ?? null;
            return 'rendered';
        });

        $this->controller = new ComplianceManagerDashboardController(
            $this->analytics,
            $this->frameworkRepo,
            $this->findingRepo,
            $this->auditRepo,
            $this->documentRepo,
            $this->tenantContext,
            $this->requirementRepo,
        );
        $this->controller->setContainer($this->container);
    }

    #[Test]
    public function dashboardNormalizesCompliancePercentageToCompliance(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->analytics->method('getFrameworkComparison')->willReturn([
            'frameworks' => [
                ['id' => 1, 'code' => 'ISO27001', 'name' => 'ISO 27001', 'compliance_percentage' => 75.5, 'mandatory' => true,
                 'fulfilled' => 75, 'applicable' => 100, 'total' => 100, 'in_progress' => 5, 'not_started' => 20, 'version' => '2022'],
                ['id' => 2, 'code' => 'NIS2', 'name' => 'NIS2', 'compliance_percentage' => 40.0, 'mandatory' => true,
                 'fulfilled' => 40, 'applicable' => 100, 'total' => 100, 'in_progress' => 5, 'not_started' => 55, 'version' => '1'],
            ],
            'summary' => [
                'average_compliance' => 57.75,
                'mandatory_compliance' => 57.75,
                'at_risk' => 1,
                'cross_mapping_coverage' => 100,
                'total_frameworks' => 2,
                'total_requirements' => 200,
                'total_fulfilled' => 115,
            ],
        ]);
        $this->findingRepo->method('findOpenByTenant')->willReturn([]);
        $this->auditRepo->method('findUpcoming')->willReturn([]);
        $this->stubDocumentRepo();

        $response = $this->controller->index();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $this->assertNotNull($this->renderedDashboard);
        $this->assertCount(2, $this->renderedDashboard['frameworks']);
        // Field-naming bugfix: template reads `compliance`, service exports `compliance_percentage`
        $this->assertSame(75.5, $this->renderedDashboard['frameworks'][0]['compliance']);
        $this->assertSame(40.0, $this->renderedDashboard['frameworks'][1]['compliance']);
    }

    #[Test]
    public function dashboardSortsTop3LowestCoverageCorrectly(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->analytics->method('getFrameworkComparison')->willReturn([
            'frameworks' => [
                ['id' => 1, 'code' => 'A', 'name' => 'A', 'compliance_percentage' => 90.0, 'mandatory' => true, 'fulfilled' => 90, 'applicable' => 100, 'total' => 100],
                ['id' => 2, 'code' => 'B', 'name' => 'B', 'compliance_percentage' => 30.0, 'mandatory' => true, 'fulfilled' => 30, 'applicable' => 100, 'total' => 100],
                ['id' => 3, 'code' => 'C', 'name' => 'C', 'compliance_percentage' => 60.0, 'mandatory' => true, 'fulfilled' => 60, 'applicable' => 100, 'total' => 100],
                ['id' => 4, 'code' => 'D', 'name' => 'D', 'compliance_percentage' => 10.0, 'mandatory' => true, 'fulfilled' => 10, 'applicable' => 100, 'total' => 100],
            ],
            'summary' => ['at_risk' => 3, 'cross_mapping_coverage' => 100, 'average_compliance' => 47.5, 'mandatory_compliance' => 47.5,
                'total_frameworks' => 4, 'total_requirements' => 400, 'total_fulfilled' => 190],
        ]);
        $this->findingRepo->method('findOpenByTenant')->willReturn([]);
        $this->auditRepo->method('findUpcoming')->willReturn([]);
        $this->stubDocumentRepo();

        $this->controller->index();

        $this->assertNotNull($this->renderedDashboard);
        $lowest = $this->renderedDashboard['lowest_coverage'];
        $this->assertCount(3, $lowest);
        $this->assertSame('D', $lowest[0]['code']);
        $this->assertSame('B', $lowest[1]['code']);
        $this->assertSame('C', $lowest[2]['code']);
    }

    #[Test]
    public function dashboardSurfacesAtRiskAndMappingCoverageFromSummary(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $this->analytics->method('getFrameworkComparison')->willReturn([
            'frameworks' => [],
            'summary' => [
                'at_risk' => 4,
                'cross_mapping_coverage' => 67,
                'average_compliance' => 55.5,
                'mandatory_compliance' => 50.0,
                'total_frameworks' => 0,
                'total_requirements' => 0,
                'total_fulfilled' => 0,
            ],
        ]);
        $this->findingRepo->method('findOpenByTenant')->willReturn([]);
        $this->auditRepo->method('findUpcoming')->willReturn([]);
        $this->stubDocumentRepo();

        $this->controller->index();

        $this->assertSame(4, $this->renderedDashboard['at_risk_count']);
        $this->assertSame(67, $this->renderedDashboard['mapping_coverage']);
        $this->assertSame(55.5, $this->renderedDashboard['avg_compliance']);
    }

    private function stubDocumentRepo(): void
    {
        $qb = $this->createMock(QueryBuilder::class);
        $query = $this->createMock(Query::class);
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);
        $query->method('getResult')->willReturn([]);
        $this->documentRepo->method('createQueryBuilder')->willReturn($qb);
    }
}
