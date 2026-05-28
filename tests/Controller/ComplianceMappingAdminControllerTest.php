<?php

declare(strict_types=1);

/**
 * ComplianceMappingAdminControllerTest
 *
 * Tests for ComplianceMappingAdminController — automated cross-framework mapping creation.
 * Extracted from ComplianceControllerTest after god-class split.
 */

namespace App\Tests\Controller;

use App\Controller\ComplianceMappingAdminController;
use App\Entity\ComplianceFramework;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use PHPUnit\Framework\Attributes\Test;

#[AllowMockObjectsWithoutExpectations]
class ComplianceMappingAdminControllerTest extends TestCase
{
    private MockObject $complianceFrameworkRepository;
    private MockObject $complianceRequirementRepository;
    private MockObject $complianceMappingRepository;
    private MockObject $csrfTokenManager;
    private ComplianceMappingAdminController $controller;

    protected function setUp(): void
    {
        $this->complianceFrameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);
        $this->complianceRequirementRepository = $this->createMock(ComplianceRequirementRepository::class);
        $this->complianceMappingRepository = $this->createMock(ComplianceMappingRepository::class);
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('translated message');

        $this->controller = new ComplianceMappingAdminController(
            $this->complianceFrameworkRepository,
            $this->complianceRequirementRepository,
            $this->complianceMappingRepository,
            $this->csrfTokenManager,
            $translator
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

    #[Test]
    public function testCreateComparisonMappingsWithValidCSRFToken(): void
    {
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_X-CSRF-Token' => 'valid-token'],
            json_encode(['framework1_id' => 1, 'framework2_id' => 2])
        );

        $this->csrfTokenManager->method('isTokenValid')
            ->willReturn(true);

        $this->complianceFrameworkRepository->method('find')
            ->willReturn(null);

        $response = $this->controller->createComparisonMappings($request);

        // Should return 404 since frameworks don't exist
        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    #[Test]
    public function testCreateComparisonMappingsWithInvalidCSRFToken(): void
    {
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_X-CSRF-Token' => 'invalid-token'],
            json_encode(['framework1_id' => 1, 'framework2_id' => 2])
        );

        $this->csrfTokenManager->method('isTokenValid')
            ->willReturn(false);

        $response = $this->controller->createComparisonMappings($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Invalid CSRF token', $data['message']);
    }

    #[Test]
    public function testCreateComparisonMappingsWithMissingFrameworkIds(): void
    {
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_X-CSRF-Token' => 'valid-token'],
            json_encode(['framework1_id' => null])
        );

        $this->csrfTokenManager->method('isTokenValid')
            ->willReturn(true);

        $response = $this->controller->createComparisonMappings($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    #[Test]
    public function testCreateComparisonMappingsWithSameFrameworkIds(): void
    {
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_X-CSRF-Token' => 'valid-token'],
            json_encode(['framework1_id' => 1, 'framework2_id' => 1])
        );

        $this->csrfTokenManager->method('isTokenValid')
            ->willReturn(true);

        $response = $this->controller->createComparisonMappings($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(400, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    #[Test]
    public function testCreateComparisonMappingsWithNonExistentFrameworks(): void
    {
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_X-CSRF-Token' => 'valid-token'],
            json_encode(['framework1_id' => 999, 'framework2_id' => 998])
        );

        $this->csrfTokenManager->method('isTokenValid')
            ->willReturn(true);

        $this->complianceFrameworkRepository->method('find')
            ->willReturn(null);

        $response = $this->controller->createComparisonMappings($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    #[Test]
    public function testCreateCrossFrameworkMappingsWithValidCSRFToken(): void
    {
        $iso27001 = $this->createFramework(1, 'ISO 27001', 'ISO27001');
        $frameworks = [$iso27001];

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_X-CSRF-Token' => 'valid-token'],
            json_encode(['batch' => 0, 'batch_size' => 50])
        );

        $this->csrfTokenManager->method('isTokenValid')
            ->willReturn(true);

        $this->complianceFrameworkRepository->method('findOneBy')
            ->willReturn($iso27001);

        $this->complianceFrameworkRepository->method('findAll')
            ->willReturn($frameworks);

        $response = $this->controller->createCrossFrameworkMappings($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        // Should fail because only 1 framework exists (need at least 2)
        $this->assertFalse($data['success']);
    }

    #[Test]
    public function testCreateCrossFrameworkMappingsWithInvalidCSRFToken(): void
    {
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_X-CSRF-Token' => 'invalid-token'],
            json_encode(['batch' => 0])
        );

        $this->csrfTokenManager->method('isTokenValid')
            ->willReturn(false);

        $response = $this->controller->createCrossFrameworkMappings($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
        $this->assertSame('Invalid CSRF token', $data['message']);
    }

    #[Test]
    public function testCreateCrossFrameworkMappingsWithoutISO27001(): void
    {
        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_X-CSRF-Token' => 'valid-token'],
            json_encode(['batch' => 0])
        );

        $this->csrfTokenManager->method('isTokenValid')
            ->willReturn(true);

        $this->complianceFrameworkRepository->method('findOneBy')
            ->willReturn(null);

        $response = $this->controller->createCrossFrameworkMappings($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }

    #[Test]
    public function testCreateCrossFrameworkMappingsWithInsufficientFrameworks(): void
    {
        $iso27001 = $this->createFramework(1, 'ISO 27001', 'ISO27001');

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_X-CSRF-Token' => 'valid-token'],
            json_encode(['batch' => 0])
        );

        $this->csrfTokenManager->method('isTokenValid')
            ->willReturn(true);

        $this->complianceFrameworkRepository->method('findOneBy')
            ->willReturn($iso27001);

        $this->complianceFrameworkRepository->method('findAll')
            ->willReturn([$iso27001]);

        $response = $this->controller->createCrossFrameworkMappings($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        $this->assertFalse($data['success']);
    }
}
