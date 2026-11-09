<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Api\ModuleFieldContextBuilder;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * S24: a field of a switched-off module ({@see \App\Attribute\ModuleField}) is
 * not in the API answer ({@see ModuleFieldContextBuilder}), so it is no
 * filter or sort key either — otherwise `?notificationRequired=true` would
 * still tell which incidents carry the hidden NIS-2 flag.
 *
 * The request is refused with 400 instead of silently ignoring the filter: a
 * client that filters on such a field would otherwise get an unfiltered list
 * and take it for the filtered one. Runs on sub-requests too (MCP gateway;
 * the gateway already drops these filters for the assistant).
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onRequest', priority: 6)]
final readonly class ApiModuleFieldFilterListener
{
    public function __construct(
        private ModuleFieldContextBuilder $moduleFields,
    ) {
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        $resourceClass = $request->attributes->get('_api_resource_class');
        if (!is_string($resourceClass) || !class_exists($resourceClass) || $request->query->count() === 0) {
            return;
        }
        $inactive = $this->moduleFields->inactiveModuleFields($resourceClass);
        if ($inactive === []) {
            return;
        }

        $used = [];
        foreach ($request->query->all() as $key => $value) {
            $key = (string) $key;
            if ($key === 'order' && is_array($value)) {
                $used = [...$used, ...array_map('strval', array_keys($value))];
                continue;
            }
            $used[] = $key;
        }
        $refused = array_values(array_intersect(
            array_map(static fn (string $key): string => (string) preg_split('/[.\[_]/', $key)[0], $used),
            $inactive,
        ));
        if ($refused === []) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'title' => 'Bad Request',
            'detail' => sprintf(
                'Filter or sort on a field of a module that is switched off: %s.',
                implode(', ', array_unique($refused)),
            ),
            'status' => Response::HTTP_BAD_REQUEST,
        ], Response::HTTP_BAD_REQUEST));
    }
}
