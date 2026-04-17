<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Entity\User;
use App\Event\FrameworkActivatedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Entry point for framework activation.
 *
 * Fires FrameworkActivatedEvent which CreateInheritanceSuggestionsListener picks up
 * (WS-1 mapping-based inheritance suggestions). Respects feature flag
 * compliance.mapping_inheritance.enabled (CM-Auflage 4 rollback safety).
 */
class ComplianceFrameworkActivationService
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
        private readonly bool $inheritanceEnabled,
    ) {
    }

    public function activate(Tenant $tenant, ComplianceFramework $framework, User $activatedBy): void
    {
        $this->auditLogger->logCustom(
            'compliance.framework.activated',
            'ComplianceFramework',
            $framework->getId(),
            null,
            [
                'tenant_id' => $tenant->getId(),
                'framework_code' => $framework->getCode(),
                'activated_by' => $activatedBy->getId(),
                'inheritance_enabled' => $this->inheritanceEnabled,
            ],
            sprintf('Framework %s activated for tenant #%d', $framework->getCode(), $tenant->getId()),
        );

        if (!$this->inheritanceEnabled) {
            $this->logger->info('Framework activated, inheritance suggestions skipped (feature flag off)', [
                'framework' => $framework->getCode(),
                'tenant' => $tenant->getId(),
            ]);
            return;
        }

        $this->dispatcher->dispatch(new FrameworkActivatedEvent($tenant, $framework, $activatedBy));
    }
}
