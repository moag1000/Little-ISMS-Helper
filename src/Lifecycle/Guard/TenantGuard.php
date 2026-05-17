<?php

declare(strict_types=1);

namespace App\Lifecycle\Guard;

use App\Service\TenantContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Workflow\Event\GuardEvent;

/**
 * Blocks lifecycle transitions where the subject belongs to a tenant
 * other than the current request's tenant. Defensive against tenant-
 * scoping bugs upstream. Subscribes to ALL workflow guard events.
 */
final class TenantGuard implements EventSubscriberInterface
{
    public function __construct(
        private readonly TenantContext $tenantContext,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            'workflow.guard' => ['onGuard', 100], // highest priority — short-circuit fast
        ];
    }

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
