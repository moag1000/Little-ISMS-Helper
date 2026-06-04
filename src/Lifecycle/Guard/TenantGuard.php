<?php

declare(strict_types=1);

namespace App\Lifecycle\Guard;

use App\Service\TenantContext;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\GuardEvent;

/**
 * Blocks lifecycle transitions where the subject belongs to a tenant
 * other than the current request's tenant. Defensive against tenant-
 * scoping bugs upstream. Subscribes to ALL workflow guard events.
 */
#[AsEventListener(event: 'workflow.guard', method: 'onGuard', priority: 100)]
final class TenantGuard
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public function onGuard(GuardEvent $event): void
    {
        $subject = $event->getSubject();
        if (!method_exists($subject, 'getTenant')) {
            return; // non-tenant-scoped entity; skip
        }

        $currentTenant = $this->tenantContext->getCurrentTenant();
        $subjectTenant = $subject->getTenant();

        if ($currentTenant === null || $subjectTenant === null) {
            $event->setBlocked(true, 'Lifecycle transition requires tenant context.');
            return;
        }

        if ($currentTenant->getId() !== $subjectTenant->getId()) {
            $event->setBlocked(true, 'Cross-tenant lifecycle transition forbidden.');
        }
    }
}
