<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\CertificationBundleController;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceFrameworkRepository;
use App\Service\CertBundleReadinessService;
use App\Service\Export\CertificationBundleExporter;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Translation\LocaleSwitcher;
use Twig\Environment;

/**
 * V4-EF-8: Unit tests for CertificationBundleController::preflight().
 *
 * CertificationBundleExporter is declared `final` — instantiate it via
 * ReflectionClass::newInstanceWithoutConstructor() to bypass the constructor
 * while keeping it as a real (inert) object reference in the controller.
 */
#[AllowMockObjectsWithoutExpectations]
class CertificationBundleControllerTest extends TestCase
{
    private MockObject $tenantContext;
    private MockObject $security;
    private MockObject $localeSwitcher;
    private MockObject $frameworkRepository;
    private MockObject $readinessService;
    private MockObject $container;
    private MockObject $twig;
    private CertificationBundleController $controller;
    private ?array $renderedContext = null;

    protected function setUp(): void
    {
        // CertificationBundleExporter is final — bypass constructor via reflection
        $exporterRef = new ReflectionClass(CertificationBundleExporter::class);
        /** @var CertificationBundleExporter $exporterStub */
        $exporterStub = $exporterRef->newInstanceWithoutConstructor();

        $this->tenantContext        = $this->createMock(TenantContext::class);
        $this->security             = $this->createMock(Security::class);
        $this->localeSwitcher       = $this->createMock(LocaleSwitcher::class);
        $this->frameworkRepository  = $this->createMock(ComplianceFrameworkRepository::class);
        $this->readinessService     = $this->createMock(CertBundleReadinessService::class);
        $this->container            = $this->createMock(ContainerInterface::class);
        $this->twig                 = $this->createMock(Environment::class);

        $self = $this;
        $this->container->method('has')->willReturnCallback(static fn(string $id) => in_array($id, ['twig'], true));
        $this->container->method('get')->willReturnCallback(static function (string $id) use ($self) {
            return match ($id) {
                'twig' => $self->twig,
                default => null,
            };
        });

        $this->twig->method('render')->willReturnCallback(function (string $tpl, array $ctx = []) {
            $this->renderedContext = $ctx;
            return 'rendered';
        });

        $this->controller = new CertificationBundleController(
            $exporterStub,
            $this->tenantContext,
            $this->security,
            $this->localeSwitcher,
            $this->frameworkRepository,
            $this->readinessService,
        );
        $this->controller->setContainer($this->container);
    }

    private function stubUser(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $user   = $this->createMock(User::class);
        $user->method('getTenant')->willReturn($tenant);
        $this->security->method('getUser')->willReturn($user);
    }

    private function stubReadinessReady(): void
    {
        $this->readinessService->method('check')->willReturn([
            'ready'    => true,
            'score'    => 100,
            'blockers' => [],
            'warnings' => [],
            'checks'   => [
                'documents_approved'        => true,
                'policy_acknowledgements'   => true,
                'findings_closed'           => true,
                'risk_assessment_current'   => true,
                'management_review_current' => true,
            ],
        ]);
    }

    #[Test]
    public function preflightRendersWithReadinessData(): void
    {
        $this->stubUser();
        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([]);
        $this->stubReadinessReady();

        $request  = new Request(['framework' => 'ISO27001']);
        $response = $this->controller->preflight($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotNull($this->renderedContext);
        $this->assertArrayHasKey('readiness', $this->renderedContext);
        $this->assertTrue($this->renderedContext['readiness']['ready']);
        $this->assertSame(100, $this->renderedContext['readiness']['score']);
    }

    #[Test]
    public function preflightPassesBlockersToTemplate(): void
    {
        $this->stubUser();
        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([]);
        $this->readinessService->method('check')->willReturn([
            'ready'    => false,
            'score'    => 60,
            'blockers' => [
                ['type' => 'open_major_findings', 'description' => '...', 'severity' => 'critical', 'count' => 2],
            ],
            'warnings' => [],
            'checks'   => [
                'documents_approved'        => true,
                'policy_acknowledgements'   => true,
                'findings_closed'           => false,
                'risk_assessment_current'   => true,
                'management_review_current' => true,
            ],
        ]);

        $request  = new Request();
        $response = $this->controller->preflight($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($this->renderedContext['readiness']['ready']);
        $this->assertCount(1, $this->renderedContext['readiness']['blockers']);
        $this->assertSame(60, $this->renderedContext['readiness']['score']);
    }

    #[Test]
    public function preflightDefaultsToISO27001WhenNoFrameworkRequested(): void
    {
        $this->stubUser();
        $this->frameworkRepository->method('findActiveFrameworks')->willReturn([]);
        $this->stubReadinessReady();

        $request  = new Request(); // no framework param
        $response = $this->controller->preflight($request);

        $this->assertSame(200, $response->getStatusCode());
        // Falls back to ISO27001 when no framework requested
        $this->assertSame('ISO27001', $this->renderedContext['selected_framework_code']);
    }
}
