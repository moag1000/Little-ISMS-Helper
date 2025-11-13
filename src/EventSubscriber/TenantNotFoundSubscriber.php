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

        // Add flash message
        $session = $this->requestStack->getSession();
        $session->getFlashBag()->add(
            'danger',
            'Der angeforderte Mandant wurde nicht gefunden. Möglicherweise wurde er gelöscht.'
        );

        // Redirect to appropriate page
        if (str_contains($path, '/admin/tenants/corporate-structure')) {
            $redirectUrl = $this->urlGenerator->generate('tenant_management_corporate_structure');
        } elseif (str_contains($path, '/admin/tenants')) {
            $redirectUrl = $this->urlGenerator->generate('tenant_management_index');
        } else {
            $redirectUrl = $this->urlGenerator->generate('admin_dashboard');
        }

        $response = new RedirectResponse($redirectUrl);
        $event->setResponse($response);
    }
}
