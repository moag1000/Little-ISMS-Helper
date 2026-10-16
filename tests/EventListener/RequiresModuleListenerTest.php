<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Attribute\RequiresModule;
use App\EventListener\RequiresModuleListener;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Central #[RequiresModule] enforcement: an inactive module must stop the
 * action before it runs — HTML callers are redirected to the dashboard with a
 * flash, JSON callers get a 403 envelope.
 */
final class RequiresModuleListenerTest extends TestCase
{
    #[Test]
    public function controllerWithoutAttributeIsLeftUntouched(): void
    {
        $controller = [new UngatedFixtureController(), 'index'];
        $event = $this->event($controller, Request::create('/en/plain'));

        $this->listener([])->onControllerArguments($event);

        self::assertSame($controller, $event->getController());
    }

    #[Test]
    public function activeModuleLetsTheActionRun(): void
    {
        $controller = [new ClassGatedFixtureController(), 'index'];
        $event = $this->event($controller, Request::create('/en/dora'));

        $this->listener(['nis2_dora'])->onControllerArguments($event);

        self::assertSame($controller, $event->getController());
    }

    #[Test]
    public function inactiveModuleRedirectsHtmlRequestsToTheDashboardWithFlash(): void
    {
        $request = Request::create('/en/dora');
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $event = $this->event([new ClassGatedFixtureController(), 'index'], $request);

        $this->listener([])->onControllerArguments($event);
        $response = ($event->getController())();

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/en/dashboard', $response->getTargetUrl());
        self::assertSame(['module_not_active:nis2_dora'], $session->getFlashBag()->get('warning'));
    }

    #[Test]
    public function inactiveModuleAnswersJsonForActionsReturningJsonResponse(): void
    {
        // No JSON Accept header — the declared return type alone decides.
        $event = $this->event([new ClassGatedFixtureController(), 'api'], Request::create('/en/dora/api', 'POST'));

        $this->listener([])->onControllerArguments($event);
        $response = ($event->getController())();

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame('module_not_active', $payload['error']);
        self::assertSame('nis2_dora', $payload['module']);
    }

    #[Test]
    public function inactiveModuleAnswersJsonForXhrCallers(): void
    {
        $request = Request::create('/en/dora');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $event = $this->event([new ClassGatedFixtureController(), 'index'], $request);

        $this->listener([])->onControllerArguments($event);
        $response = ($event->getController())();

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
    }

    #[Test]
    public function classAndMethodAttributesMustAllBeActive(): void
    {
        $controller = [new ClassGatedFixtureController(), 'registration'];

        $onlyDora = $this->event($controller, Request::create('/en/dora/registration'));
        $this->listener(['nis2_dora'])->onControllerArguments($onlyDora);
        self::assertNotSame($controller, $onlyDora->getController(), 'method-level module must be enforced too');

        $both = $this->event($controller, Request::create('/en/dora/registration'));
        $this->listener(['nis2_dora', 'eu_authority_reporting'])->onControllerArguments($both);
        self::assertSame($controller, $both->getController());
    }

    #[Test]
    public function methodLevelAttributeOnlyGuardsThatAction(): void
    {
        $gated = $this->event([new MethodGatedFixtureController(), 'bcmImpact'], Request::create('/en/incident/1/bcm'));
        $this->listener([])->onControllerArguments($gated);
        self::assertInstanceOf(RedirectResponse::class, ($gated->getController())());

        $open = [new MethodGatedFixtureController(), 'show'];
        $ungated = $this->event($open, Request::create('/en/incident/1'));
        $this->listener([])->onControllerArguments($ungated);
        self::assertSame($open, $ungated->getController());
    }

    /**
     * @param list<string> $activeModules
     */
    private function listener(array $activeModules): RequiresModuleListener
    {
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActive')->willReturnCallback(
            static fn (string $key): bool => in_array($key, $activeModules, true),
        );

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/en/dashboard');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []): string => $id === 'common.module_not_active'
                ? 'module_not_active:' . ($params['%module%'] ?? '')
                : $id,
        );

        return new RequiresModuleListener($modules, $urls, $translator);
    }

    private function event(callable $controller, Request $request): ControllerArgumentsEvent
    {
        return new ControllerArgumentsEvent(
            $this->createStub(HttpKernelInterface::class),
            $controller,
            [],
            $request,
            HttpKernelInterface::MAIN_REQUEST,
        );
    }
}

final class UngatedFixtureController
{
    public function index(): Response
    {
        return new Response('ok');
    }
}

#[RequiresModule('nis2_dora')]
final class ClassGatedFixtureController
{
    public function index(): Response
    {
        return new Response('ok');
    }

    public function api(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }

    #[RequiresModule('eu_authority_reporting')]
    public function registration(): Response
    {
        return new Response('ok');
    }
}

final class MethodGatedFixtureController
{
    public function show(): Response
    {
        return new Response('ok');
    }

    #[RequiresModule('bcm')]
    public function bcmImpact(): Response
    {
        return new Response('ok');
    }
}
