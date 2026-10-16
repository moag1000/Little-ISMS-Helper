<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Tenant;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Handles 404 errors when Tenant entities are not found
 * Provides user-friendly error messages and redirects
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, method: 'onKernelException')]
final class TenantNotFoundSubscriber
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function onKernelException(ExceptionEvent $exceptionEvent): void
    {
        $throwable = $exceptionEvent->getThrowable();

        // Only handle NotFoundHttpException
        if (!$throwable instanceof NotFoundHttpException) {
            return;
        }

        // Check if it's a Tenant-related 404
        $message = $throwable->getMessage();
        if (!str_contains($message, Tenant::class)) {
            return;
        }

        // Get the request path to determine where to redirect
        $request = $exceptionEvent->getRequest();
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
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(
                'danger',
                $this->translator->trans('tenant.flash.not_found', [], 'tenant'),
            );
        }

        // Redirect to appropriate page (only for detail/edit pages with {id} parameter)
        if (str_contains($path, '/admin/tenants/') && $request->attributes->has('id')) {
            // Coming from tenant detail/edit page - redirect to tenant list
            $redirectUrl = $this->urlGenerator->generate('tenant_management_index');
        } else {
            // For other cases, redirect to dashboard
            $redirectUrl = $this->urlGenerator->generate('admin_dashboard');
        }

        $redirectResponse = new RedirectResponse($redirectUrl);
        $exceptionEvent->setResponse($redirectResponse);
    }
}
