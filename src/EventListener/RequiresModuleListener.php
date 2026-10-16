<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Attribute\RequiresModule;
use App\Service\ModuleConfigurationService;
use Closure;
use ReflectionException;
use ReflectionFunction;
use ReflectionNamedType;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Central enforcement of {@see RequiresModule}.
 *
 * Runs on kernel.controller_arguments with priority 10 — after Symfony's
 * IsGranted listener (priority 20), so anonymous users still get the login
 * redirect and unauthorised users a 403 before the module check reveals
 * anything about the tenant's configuration.
 *
 * Behaviour mirrors ModuleGatedControllerTrait::checkModuleActive(): HTML
 * requests are redirected to the dashboard with the shared
 * `common.module_not_active` flash; JSON/XHR callers (and actions declared
 * to return a JsonResponse) get a 403 JSON envelope so fetch()-based
 * frontends never receive an HTML redirect.
 */
#[AsEventListener(event: KernelEvents::CONTROLLER_ARGUMENTS, method: 'onControllerArguments', priority: 10)]
final readonly class RequiresModuleListener
{
    public function __construct(
        private ModuleConfigurationService $moduleConfiguration,
        private UrlGeneratorInterface $urlGenerator,
        private TranslatorInterface $translator,
    ) {
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        /** @var list<RequiresModule> $attributes */
        $attributes = $event->getAttributes(RequiresModule::class);
        if ($attributes === []) {
            return;
        }

        foreach ($attributes as $attribute) {
            if ($this->moduleConfiguration->isModuleActive($attribute->module)) {
                continue;
            }

            $response = $this->denyResponse(
                $event->getRequest(),
                $attribute->module,
                $this->returnsJson($event->getController()),
            );
            $event->setController(static fn (): Response => $response);

            return;
        }
    }

    private function denyResponse(Request $request, string $module, bool $returnsJson): Response
    {
        $message = $this->translator->trans('common.module_not_active', ['%module%' => $module], 'messages');

        if ($returnsJson || $this->expectsJson($request)) {
            return new JsonResponse(
                ['success' => false, 'error' => 'module_not_active', 'module' => $module, 'message' => $message],
                Response::HTTP_FORBIDDEN,
            );
        }

        if ($request->hasSession()) {
            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('warning', $message);
            }
        }

        return new RedirectResponse($this->urlGenerator->generate('app_dashboard'));
    }

    /**
     * True when the action is declared to return a JsonResponse — AJAX
     * endpoints keep answering JSON even when the caller sends no JSON
     * Accept header.
     */
    private function returnsJson(callable $controller): bool
    {
        try {
            $reflection = new ReflectionFunction(Closure::fromCallable($controller));
        } catch (ReflectionException) {
            return false;
        }

        $type = $reflection->getReturnType();

        return $type instanceof ReflectionNamedType
            && !$type->isBuiltin()
            && is_a($type->getName(), JsonResponse::class, true);
    }

    private function expectsJson(Request $request): bool
    {
        if (
            $request->isXmlHttpRequest()
            || $request->getRequestFormat(null) === 'json'
            || $request->getContentTypeFormat() === 'json'
        ) {
            return true;
        }

        $preferred = $request->getAcceptableContentTypes()[0] ?? '';

        return $preferred === 'application/json' || str_ends_with($preferred, '+json');
    }
}
