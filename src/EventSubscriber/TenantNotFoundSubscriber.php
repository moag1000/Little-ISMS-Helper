<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpFoundation\Session\Flash\FlashBagInterface;

/**
 * Handles 404 errors when Tenant entities are not found
 * Provides user-friendly error messages and redirects
 */
class TenantNotFoundSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::EXCEPTION => ['onKernelException', 0],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();

        // Only handle NotFoundHttpException
        if (!$exception instanceof NotFoundHttpException) {
            return;
        }

        // Check if it's a Tenant-related 404
        $message = $exception->getMessage();
        if (!str_contains($message, 'App\Entity\Tenant')) {
            return;
        }

        // Get the request path to determine where to redirect
        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $route = $request->attributes->get('_route');

        // IMPORTANT: Avoid redirect loops - don't redirect if already on index/overview pages
        $safeRoutes = [
            'tenant_management_index',
            'tenant_management_corporate_structure',
            'admin_dashboard'
        ];

        if (in_array($route, $safeRoutes)) {
            // Already on a safe page - let the exception bubble up or show error inline
            return;
        }

        // Add flash message
        $session = $this->requestStack->getSession();
        $session->getFlashBag()->add(
            'danger',
            'Der angeforderte Mandant wurde nicht gefunden. Möglicherweise wurde er gelöscht.'
        );

        // Redirect to appropriate page (only for detail/edit pages with {id} parameter)
        if (str_contains($path, '/admin/tenants/') && $request->attributes->has('id')) {
            // Coming from tenant detail/edit page - redirect to tenant list
            $redirectUrl = $this->urlGenerator->generate('tenant_management_index');
        } else {
            // For other cases, redirect to dashboard
            $redirectUrl = $this->urlGenerator->generate('admin_dashboard');
        }

        $response = new RedirectResponse($redirectUrl);
        $event->setResponse($response);
    }
}
