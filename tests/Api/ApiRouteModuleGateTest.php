<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Attribute\RequiresModule;
use App\EventListener\ApiResourceModuleListener;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The API module gate ({@see ApiResourceModuleListener}) keys on the
 * `_api_resource_class` route attribute. This test walks the REAL route
 * collection so a resource operation without that attribute, a module-owned
 * resource the listener would let through, or a new custom /api controller
 * that nobody classified cannot slip in unnoticed (#1059).
 *
 * See docs/MODULE_GATING_GUIDE.md § "API Platform resources".
 */
final class ApiRouteModuleGateTest extends KernelTestCase
{
    /**
     * API Platform's own infrastructure routes: documentation, entrypoint,
     * JSON-LD contexts, not-exposed placeholders. They serve no resource data.
     */
    private const array API_PLATFORM_INFRASTRUCTURE_ROUTES = [
        'api_doc',
        'api_entrypoint',
        'api_genid',
        'api_validation_errors',
        'api_jsonld_context',
    ];

    /**
     * Every custom (non-API-Platform) controller under /api, with its module
     * decision. The API Platform gate does not cover these: each one gates
     * itself. 'core' = serves no module-owned data (always available).
     * A list value = the action's #[RequiresModule] key; 'self' = the
     * controller checks the owning module per request (QuickCreate: per
     * entity type).
     *
     * @var array<string, string|array<string, string>>
     */
    private const array CUSTOM_API_CONTROLLERS = [
        'App\Controller\Api\AlvaHintFormController' => 'core', // hints are module-filtered by the evaluator
        'App\Controller\Api\LiveCountsController' => 'core',
        'App\Controller\Api\PushSubscriptionController' => 'core', // browser push of the user's own notifications
        'App\Controller\Api\QuickCreateController' => 'self',
        'App\Controller\Api\SsoDiscoveryApiController' => 'core', // authentication (required module)
        'App\Controller\Api\WorkflowStepApiController' => 'core', // workflows (required module)
        'App\Controller\CorporateStructureController' => 'core', // organisation tree
        'App\Controller\GlossaryApiController' => 'core',
        'App\Controller\SearchController' => [
            'search' => 'core', // results are module-filtered by SearchService
            'assetPreview' => 'assets',
            'riskPreview' => 'risks',
            'incidentPreview' => 'incidents',
        ],
    ];

    #[Test]
    public function everyApiPlatformOperationCarriesAnExistingResourceClass(): void
    {
        $operations = 0;
        foreach ($this->routes() as $name => $route) {
            if (!$this->isApiPlatformRoute($route)) {
                continue;
            }
            if (in_array($name, self::API_PLATFORM_INFRASTRUCTURE_ROUTES, true)) {
                continue;
            }

            $resourceClass = $route->getDefault('_api_resource_class');
            self::assertIsString($resourceClass, sprintf('API route "%s" has no _api_resource_class — the module gate cannot see it.', $name));
            self::assertTrue(class_exists($resourceClass), sprintf('API route "%s": resource class %s does not exist.', $name, $resourceClass));
            ++$operations;
        }

        self::assertGreaterThan(0, $operations, 'no API Platform operation found — route discovery is broken');
    }

    /**
     * For every module-owned resource the listener decides on the route's
     * own attributes: module off → 403, module on → passes.
     */
    #[Test]
    public function everyModuleOwnedApiResourceIsDeniedWhileItsModuleIsOff(): void
    {
        $checked = [];
        foreach ($this->routes() as $name => $route) {
            $resourceClass = $route->getDefault('_api_resource_class');
            if (!is_string($resourceClass) || !class_exists($resourceClass)) {
                continue;
            }
            $modules = array_map(
                static fn ($attribute): string => $attribute->newInstance()->module,
                (new ReflectionClass($resourceClass))->getAttributes(RequiresModule::class),
            );
            if ($modules === []) {
                continue;
            }

            foreach ($modules as $module) {
                $denied = $this->decide($route, static fn (string $key): bool => $key !== $module);
                self::assertSame(
                    Response::HTTP_FORBIDDEN,
                    $denied?->getStatusCode(),
                    sprintf('API route "%s" (%s) stays reachable while module "%s" is off.', $name, $resourceClass, $module),
                );
            }
            self::assertNull(
                $this->decide($route, static fn (): bool => true),
                sprintf('API route "%s" is denied although every module is active.', $name),
            );
            $checked[$resourceClass] = true;
        }

        self::assertNotSame([], $checked, 'no module-owned API resource found');
    }

    #[Test]
    public function everyCustomApiControllerIsClassifiedAndGated(): void
    {
        $seen = [];
        foreach ($this->routes() as $name => $route) {
            if ($this->isApiPlatformRoute($route) || !preg_match('#^(/\{_locale\})?/api(/|$)#', $route->getPath())) {
                continue;
            }
            $controller = (string) $route->getDefault('_controller');
            [$class, $method] = str_contains($controller, '::') ? explode('::', $controller, 2) : [$controller, '__invoke'];

            self::assertArrayHasKey(
                $class,
                self::CUSTOM_API_CONTROLLERS,
                sprintf(
                    'Custom /api route "%s" (%s) is not classified. The API Platform module gate does not cover it: '
                    . 'gate it with #[RequiresModule] (or a per-request check) and add it to CUSTOM_API_CONTROLLERS.',
                    $name,
                    $controller,
                ),
            );
            $seen[$class] = true;

            $decision = self::CUSTOM_API_CONTROLLERS[$class];
            if (is_array($decision)) {
                self::assertArrayHasKey($method, $decision, sprintf('%s::%s is not classified.', $class, $method));
                $decision = $decision[$method];
            }
            if ($decision === 'core' || $decision === 'self') {
                continue;
            }
            self::assertContains(
                $decision,
                $this->requiredModules($class, $method),
                sprintf('%s::%s must carry #[RequiresModule(\'%s\')].', $class, $method, $decision),
            );
        }

        self::assertSame(
            [],
            array_values(array_diff(array_keys(self::CUSTOM_API_CONTROLLERS), array_keys($seen))),
            'CUSTOM_API_CONTROLLERS lists a controller that no longer serves an /api route.',
        );
    }

    /**
     * @return iterable<string, Route>
     */
    private function routes(): iterable
    {
        self::bootKernel();
        $router = self::getContainer()->get(RouterInterface::class);

        return $router->getRouteCollection()->all();
    }

    private function isApiPlatformRoute(Route $route): bool
    {
        return $route->hasDefault('_api_resource_class')
            || $route->hasDefault('_api_operation_name')
            || str_starts_with((string) $route->getDefault('_controller'), 'api_platform.');
    }

    /**
     * @param \Closure(string): bool $isModuleActive
     */
    private function decide(Route $route, \Closure $isModuleActive): ?Response
    {
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActive')->willReturnCallback($isModuleActive);
        $listener = new ApiResourceModuleListener($modules, self::getContainer()->get(TranslatorInterface::class));

        // RouterListener copies the matched route's defaults into the request attributes.
        $request = Request::create('/api/probe');
        foreach ($route->getDefaults() as $key => $value) {
            $request->attributes->set($key, $value);
        }
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $listener->onRequest($event);

        return $event->getResponse();
    }

    /**
     * @return list<string>
     */
    private function requiredModules(string $class, string $method): array
    {
        $attributes = (new ReflectionClass($class))->getAttributes(RequiresModule::class);
        if (method_exists($class, $method)) {
            $attributes = [...$attributes, ...(new \ReflectionMethod($class, $method))->getAttributes(RequiresModule::class)];
        }

        return array_map(static fn ($attribute): string => $attribute->newInstance()->module, $attributes);
    }
}
