<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Doctrine\TenantFilter;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Activates the Doctrine TenantFilter on every HTTP request.
 *
 * Sets the tenant_id parameter from the current user's tenant. Anonymous
 * requests and a SUPER_ADMIN without an organisation stay unscoped
 * ({@see TenantFilter::UNSCOPED}); any other account without an organisation
 * is scoped to no organisation ({@see TenantFilter::NO_ORGANISATION}).
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

        $filter->setParameter('tenant_id', match (true) {
            $tenant !== null && $tenant->getId() !== null => (string) $tenant->getId(),
            // Anonymous requests (login, setup wizard, public share links) and the
            // instance operator stay unscoped.
            $user === null, $this->security->isGranted('ROLE_SUPER_ADMIN') => TenantFilter::UNSCOPED,
            // Any other account without an organisation sees no organisation's data.
            default => TenantFilter::NO_ORGANISATION,
        });
    }
}
