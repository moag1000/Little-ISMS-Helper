<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Attribute\RequiresModule;
use App\Service\ModuleConfigurationService;
use ReflectionClass;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Module gate for API Platform resources.
 *
 * {@see RequiresModuleListener} only sees controller actions; API Platform
 * operations run through its own placeholder controller, so a module-owned
 * entity stayed readable and writable under /api/... while its module was off.
 * The module key is declared once, on the entity class
 * (`#[RequiresModule('bcm')] class BusinessContinuityPlan`), and enforced here
 * for every operation of that resource via the `_api_resource_class` request
 * attribute API Platform's routes carry.
 *
 * Priority 7 runs after the firewall (8), so the tenant is resolved from the
 * authenticated user and anonymous callers still get the 401 first.
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: 7)]
final readonly class ApiResourceModuleListener
{
    public function __construct(
        private ModuleConfigurationService $moduleConfiguration,
        private TranslatorInterface $translator,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $resourceClass = $event->getRequest()->attributes->get('_api_resource_class');
        if (!is_string($resourceClass) || !class_exists($resourceClass)) {
            return;
        }

        foreach ((new ReflectionClass($resourceClass))->getAttributes(RequiresModule::class) as $attribute) {
            $module = $attribute->newInstance()->module;
            if ($this->moduleConfiguration->isModuleActive($module)) {
                continue;
            }

            $event->setResponse(new JsonResponse(
                [
                    'success' => false,
                    'error' => 'module_not_active',
                    'module' => $module,
                    'message' => $this->translator->trans('common.module_not_active', ['%module%' => $module], 'messages'),
                ],
                Response::HTTP_FORBIDDEN,
            ));

            return;
        }
    }
}
