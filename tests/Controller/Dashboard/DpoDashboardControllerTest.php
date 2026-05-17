<?php

declare(strict_types=1);

namespace App\Tests\Controller\Dashboard;

use App\Controller\Dashboard\DpoDashboardController;
use App\Entity\DataBreach;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Repository\DataBreachRepository;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Repository\ProcessingActivityRepository;
use App\Service\RoleDashboardService;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Smoke tests for DpoDashboardController.
 *
 * Verifies:
 *   1. GET returns HTTP 200 for ROLE_DPO (200 when tenant present).
 *   2. Dashboard payload contains all expected KPI keys.
 *   3. Repo results are forwarded correctly to the template context.
 *   4. Not-found is thrown when tenant context returns null.
 */
#[AllowMockObjectsWithoutExpectations]
class DpoDashboardControllerTest extends TestCase
{
    private MockObject $tenantContext;
    private MockObject $dataBreachRepo;
    private MockObject $dpiaRepo;
    private MockObject $processingActivityRepo;
    private MockObject $roleDashboardService;
    private MockObject $container;
    private MockObject $twig;
    private DpoDashboardController $controller;
    private ?array $renderedDashboard = null;

    protected function setUp(): void
    {
        $this->tenantContext          = $this->createMock(TenantContext::class);
        $this->dataBreachRepo         = $this->createMock(DataBreachRepository::class);
        $this->dpiaRepo               = $this->createMock(DataProtectionImpactAssessmentRepository::class);
        $this->processingActivityRepo = $this->createMock(ProcessingActivityRepository::class);
        $this->roleDashboardService   = $this->createMock(RoleDashboardService::class);
        $this->container              = $this->createMock(ContainerInterface::class);
        $this->twig                   = $this->createMock(Environment::class);

        $self = $this;
        $this->container->method('has')->willReturnCallback(
            static fn(string $id) => in_array($id, ['twig'], true),
        );
        $this->container->method('get')->willReturnCallback(
            static function (string $id) use ($self) {
                return match ($id) {
                    'twig'  => $self->twig,
                    default => null,
                };
            },
        );
        $this->twig->method('render')->willReturnCallback(function (string $tpl, array $ctx = []) {
            $this->renderedDashboard = $ctx['dashboard'] ?? null;
            return 'rendered';
        });

        $this->roleDashboardService->method('getPendingApprovals')->willReturn([]);
        $this->roleDashboardService->method('getLifecycleStuck')->willReturn([]);

        $this->controller = new DpoDashboardController(
            $this->tenantContext,
            $this->dataBreachRepo,
            $this->dpiaRepo,
            $this->processingActivityRepo,
            $this->roleDashboardService,
        );
        $this->controller->setContainer($this->container);
    }

    #[Test]
    public function invokeReturns200WhenTenantPresent(): void
    {
        $this->stubTenantAndEmptyRepos();

        $response = ($this->controller)();

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    #[Test]
    public function dashboardPayloadContainsAllExpectedKpiKeys(): void
    {
        $this->stubTenantAndEmptyRepos();

        ($this->controller)();

        $this->assertNotNull($this->renderedDashboard);
        $requiredKeys = [
            'open_breaches',
            'open_breaches_count',
            'ticking_72h_count',
            'dpias_in_progress',
            'dpias_in_progress_count',
            'activities_needing_dpia',
            'activities_due_review',
            'third_country_transfers',
            // Z.0 — workflow transparency keys
            'pending_approvals',
            'lifecycle_stuck',
        ];
        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $this->renderedDashboard, "Missing dashboard key: $key");
        }
    }

    #[Test]
    public function dashboardCountsReflectRepoResults(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);

        $breach     = $this->createMock(DataBreach::class);
        $dpiaDraft  = $this->createMock(DataProtectionImpactAssessment::class);
        $dpiaReview = $this->createMock(DataProtectionImpactAssessment::class);
        $activity   = $this->createMock(ProcessingActivity::class);

        $this->dataBreachRepo->method('findIncomplete')->willReturn([$breach]);
        $this->dataBreachRepo->method('findAuthorityNotification72hTicking')->willReturn([$breach]);
        $this->dpiaRepo->method('findDrafts')->willReturn([$dpiaDraft]);
        $this->dpiaRepo->method('findInReview')->willReturn([$dpiaReview]);
        $this->processingActivityRepo->method('findRequiringDPIA')->willReturn([$activity]);
        $this->processingActivityRepo->method('findDueForReview')->willReturn([]);
        $this->processingActivityRepo->method('findWithThirdCountryTransfers')->willReturn([]);

        ($this->controller)();

        $this->assertSame(1, $this->renderedDashboard['open_breaches_count']);
        $this->assertSame(1, $this->renderedDashboard['ticking_72h_count']);
        $this->assertSame(2, $this->renderedDashboard['dpias_in_progress_count']); // draft + review
        $this->assertCount(1, $this->renderedDashboard['activities_needing_dpia']);
    }

    #[Test]
    public function throwsNotFoundWhenNoTenant(): void
    {
        $this->tenantContext->method('getCurrentTenant')->willReturn(null);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        ($this->controller)();
    }

    private function stubTenantAndEmptyRepos(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $this->tenantContext->method('getCurrentTenant')->willReturn($tenant);
        $this->dataBreachRepo->method('findIncomplete')->willReturn([]);
        $this->dataBreachRepo->method('findAuthorityNotification72hTicking')->willReturn([]);
        $this->dpiaRepo->method('findDrafts')->willReturn([]);
        $this->dpiaRepo->method('findInReview')->willReturn([]);
        $this->processingActivityRepo->method('findRequiringDPIA')->willReturn([]);
        $this->processingActivityRepo->method('findDueForReview')->willReturn([]);
        $this->processingActivityRepo->method('findWithThirdCountryTransfers')->willReturn([]);
    }
}
