<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Controller\Api\WorkflowStepApiController;
use App\Entity\Workflow;
use App\Entity\WorkflowStep;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * The legacy workflow-step API is read-only since Y.4: listing still works
 * for historical display, every former write endpoint answers 410 Gone and
 * points at the YAML overlay editor. The controller must not even be able to
 * write (no EntityManager dependency).
 */
final class WorkflowStepApiControllerTest extends TestCase
{
    private WorkflowStepApiController $controller;

    protected function setUp(): void
    {
        $router = $this->createStub(RouterInterface::class);
        $router->method('generate')->willReturnCallback(
            static fn (string $name, array $params, int $type): string => $name === 'admin_workflow_overlay_index'
                && $type === UrlGeneratorInterface::ABSOLUTE_PATH ? '/en/admin/workflows' : '/unexpected/' . $name,
        );

        $container = new Container();
        $container->set('router', $router);

        $this->controller = new WorkflowStepApiController();
        $this->controller->setContainer($container);
    }

    #[Test]
    public function listStepsSerializesStepsReadOnly(): void
    {
        $step = $this->createConfiguredStub(WorkflowStep::class, [
            'getId' => 7,
            'getName' => 'Initial Assessment',
            'getDescription' => null,
            'getStepOrder' => 1,
            'getStepType' => 'approval',
            'getApproverRole' => 'ROLE_DPO',
            'getApproverUsers' => null,
            'isRequired' => true,
            'getDaysToComplete' => 3,
        ]);
        $workflow = $this->createStub(Workflow::class);
        $workflow->method('getSteps')->willReturn(new ArrayCollection([$step]));

        $data = json_decode((string) $this->controller->listSteps($workflow)->getContent(), true);

        self::assertTrue($data['success']);
        self::assertTrue($data['read_only']);
        self::assertSame('Initial Assessment', $data['steps'][0]['name']);
        self::assertSame([], $data['steps'][0]['approverUsers']);
        self::assertSame(3, $data['steps'][0]['daysToComplete']);
    }

    #[Test]
    public function writeEndpointsAnswerGoneWithOverlayPointer(): void
    {
        $response = $this->controller->gone();

        self::assertSame(Response::HTTP_GONE, $response->getStatusCode());
        $data = json_decode((string) $response->getContent(), true);
        self::assertFalse($data['success']);
        self::assertSame('gone', $data['error']);
        self::assertStringContainsString('config/workflows/regulatory', $data['message']);
        self::assertSame('/en/admin/workflows', $data['overlay_url']);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function formerWriteRoutes(): iterable
    {
        yield 'add' => ['api_workflow_steps_add', ['POST']];
        yield 'update' => ['api_workflow_step_update', ['PUT', 'PATCH']];
        yield 'delete' => ['api_workflow_step_delete', ['DELETE']];
        yield 'reorder' => ['api_workflow_steps_reorder', ['POST']];
        yield 'duplicate' => ['api_workflow_step_duplicate', ['POST']];
        yield 'apply template' => ['api_workflow_apply_template', ['POST']];
        yield 'template catalogue' => ['api_workflow_templates', ['GET']];
    }

    /** @param list<string> $methods */
    #[Test]
    #[DataProvider('formerWriteRoutes')]
    public function formerWriteRouteIsBoundToGone(string $routeName, array $methods): void
    {
        $routes = [];
        foreach ((new \ReflectionMethod(WorkflowStepApiController::class, 'gone'))->getAttributes(Route::class) as $attr) {
            $route = $attr->newInstance();
            $routes[$route->name] = $route->methods;
        }

        self::assertArrayHasKey($routeName, $routes);
        self::assertSame($methods, $routes[$routeName]);
    }

    #[Test]
    public function controllerHasNoWriteDependencies(): void
    {
        self::assertNull((new \ReflectionClass(WorkflowStepApiController::class))->getConstructor());
    }
}
