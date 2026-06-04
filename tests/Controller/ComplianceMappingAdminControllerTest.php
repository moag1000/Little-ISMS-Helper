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
use App\Entity\ComplianceMapping;
use Doctrine\ORM\EntityManagerInterface;
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
    private MockObject $entityManager;
    private ComplianceMappingAdminController $controller;

    protected function setUp(): void
    {
        $this->complianceFrameworkRepository = $this->createMock(ComplianceFrameworkRepository::class);
        $this->complianceRequirementRepository = $this->createMock(ComplianceRequirementRepository::class);
        $this->complianceMappingRepository = $this->createMock(ComplianceMappingRepository::class);
        $this->csrfTokenManager = $this->createMock(CsrfTokenManagerInterface::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);

        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturn('translated message');

        $this->controller = new ComplianceMappingAdminController(
            $this->complianceFrameworkRepository,
            $this->complianceRequirementRepository,
            $this->complianceMappingRepository,
            $this->csrfTokenManager,
            $this->entityManager,
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

        // AbstractController::getParameter() resolves through the 'parameter_bag'
        // service — provide one (kernel.debug=false) so actions that read params
        // (e.g. the debug-payload toggle) don't blow up in the unit harness.
        $parameterBag = $this->createMock(\Symfony\Component\DependencyInjection\ParameterBag\ContainerBagInterface::class);
        $parameterBag->method('get')->willReturn(false);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(function ($id) use ($twig, $router, $requestStack, $parameterBag) {
            return match ($id) {
                'twig' => $twig,
                'router' => $router,
                'request_stack' => $requestStack,
                'parameter_bag' => $parameterBag,
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
    public function testCreateCrossFrameworkMappingsSurvivesEntityManagerAndOrphanedMapping(): void
    {
        // Regression for the hard 500 on /compliance/frameworks/create-mappings:
        //  (a) the action used to call the *protected* repo->getEntityManager()
        //      externally → a \Error that escaped catch(Exception) → 500. The EM
        //      is now constructor-injected.
        //  (b) an orphaned existing mapping (source/target requirement gone) made
        //      getId()-on-null throw a \TypeError → also a 500. Now skipped.
        $iso27001 = $this->createFramework(1, 'ISO 27001', 'ISO27001');
        $other = $this->createFramework(2, 'TISAX', 'TISAX');

        $request = new Request(
            [],
            [],
            [],
            [],
            [],
            ['HTTP_X-CSRF-Token' => 'valid-token'],
            json_encode(['batch' => 0, 'batch_size' => 50]),
        );

        $this->csrfTokenManager->method('isTokenValid')->willReturn(true);
        $this->complianceFrameworkRepository->method('findOneBy')->willReturn($iso27001);
        $this->complianceFrameworkRepository->method('findAll')->willReturn([$iso27001, $other]);
        // No requirements → no potential mappings to create (keeps the test focused
        // on reaching the EntityManager + the orphan-skip guard).
        $this->complianceRequirementRepository->method('findBy')->willReturn([]);

        // Poisoned existing mapping — its source/target requirement is gone.
        $orphan = $this->createPartialMock(ComplianceMapping::class, ['getSourceRequirement', 'getTargetRequirement']);
        $orphan->method('getSourceRequirement')->willReturn(null);
        $orphan->method('getTargetRequirement')->willReturn(null);
        $this->complianceMappingRepository->method('findAll')->willReturn([$orphan]);

        $response = $this->controller->createCrossFrameworkMappings($request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(
            200,
            $response->getStatusCode(),
            'Reaching the EntityManager with an orphaned existing mapping must not 500.',
        );
        $data = json_decode($response->getContent(), true);
        $this->assertTrue($data['success']);
    }
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
