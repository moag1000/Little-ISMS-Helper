<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Activates the Doctrine TenantFilter on every HTTP request.
 *
 * Sets the tenant_id parameter from the current user's tenant.
 * When no user is authenticated or user has no tenant (super admin),
 * the parameter is set to 'null' which disables filtering (admin mode).
 */
#[AsEventListener(event: KernelEvents::REQUEST, method: 'onKernelRequest', priority: 7)]
final class TenantFilterSubscriber
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
    ) {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $filters = $this->entityManager->getFilters();

        if (!$filters->isEnabled('tenant_filter')) {
            return;
        }

        $filter = $filters->getFilter('tenant_filter');

        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        if ($tenant !== null && $tenant->getId() !== null) {
            $filter->setParameter('tenant_id', (string) $tenant->getId());
        } else {
            // No tenant = admin mode, filter bypasses
            $filter->setParameter('tenant_id', 'null');
        }
    }
}
